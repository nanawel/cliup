<?php

namespace {
    /**
     * @param DateInterval $interval The interval
     * @param string[] $format
     * @param string $separator
     * @return string Formatted interval string.
     */
    function human_date_interval(DateInterval $interval, $format = null, $separator = ', ') {
        if (!is_array($format)) {
            $format = [
                'months' => '%d month(s)',
                'days' => '%d day(s)',
                'hours' => '%d hour(s)',
                'minutes' => '%d minute(s)',
                'seconds' => '%d second(s)',
            ];
        }

        $p1y = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('P1Y'))->getTimeStamp();
        $p1m = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('P1M'))->getTimeStamp();
        $p1d = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('P1D'))->getTimeStamp();
        $pt1h = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('PT1H'))->getTimeStamp();
        $pt1m = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('PT1M'))->getTimeStamp();

        $result['seconds'] = (new DateTime())->setTimeStamp(0)->add($interval)->getTimeStamp();
        $result['years'] = (int) ($result['seconds'] / $p1y);
        $result['months'] = (int) (($result['seconds'] = ($result['seconds'] - ($p1y * $result['years']))) / $p1m);
        $result['days'] = (int) (($result['seconds'] = ($result['seconds'] - ($p1m * $result['months']))) / $p1d);
        $result['hours'] = (int) (($result['seconds'] = ($result['seconds'] - ($p1d * $result['days']))) / $pt1h);
        $result['minutes'] = (int) (($result['seconds'] = ($result['seconds'] - ($pt1h * $result['hours']))) / $pt1m);
        $result['seconds'] = (int) ($result['seconds'] - ($pt1m * $result['minutes']));

        $formattedResult = [];
        foreach ($format as $t => $v) {
            if ($result[$t] > 0) {
                $formattedResult[$t] = sprintf($v, $result[$t]);
            }
        }

        return implode($separator, $formattedResult);
    }

    /**
     * @see https://stackoverflow.com/a/28047922/5431347
     *
     * @param int $bytes
     * @return string
     */
    function byteConvert($bytes)
    {
        if (!$bytes) {
            return '0.00 B';
        }

        $s = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $e = floor(log($bytes, 1024));

        return round($bytes / (1024 ** $e), 2) . ' ' . $s[$e];
    }
}


namespace CLiup {

    use Defuse\Crypto\File;
    use Fig\Http\Message\StatusCodeInterface;
    use Slim\Psr7\Request;
    use Slim\Psr7\Response;
    use Slim\Routing\RouteContext;

    function log($message, $level = 'INFO') {
        file_put_contents('php://stderr', date('c') . " $level $message\n");
    }

    function generatePassword() {
        global $context;
        $dictFile = new \SplFileObject($context['WORDSLIST_FILE']);
        $passwords = [];
        $dictFile->seek(PHP_INT_MAX);
        $lineCnt = $dictFile->key();
        for ($i = 0; $i < $context['PASS_WORDS_COUNT']; $i++) {
            do {
                $dictFile->rewind();
                $dictFile->seek(random_int(1, $lineCnt));
                $word = trim($dictFile->fgets());
            } while (!$word);   // Make sure we never pick an empty word
            $passwords[] = $word;
        }

        return implode('-', $passwords);
    }

    function getUploadHash(string $password) {
        global $context;
        return sha1($context['HASH_SALT'] . $password);
    }

    function getEncryptionKey(string $password) {
        global $context;
        return sha1($context['HASH_SALT'] . strrev($password));
    }

    function getUploadDir(string $uploadHash) {
        global $context;
        return $context['UPLOAD_DIR']
            . '/' . substr($uploadHash, 0, 2)
            . '/' . substr($uploadHash, 2, 2);
    }

    function getUploadFilePath(string $uploadHash) {
        return getUploadDir($uploadHash) . "/$uploadHash";
    }

    function getUploadMetadataFilePath(string $uploadHash) {
        return getUploadDir($uploadHash) . "/$uploadHash.json";
    }

    function getUploadMetadata(string $uploadHash) {
        if (is_file($path = getUploadMetadataFilePath($uploadHash))) {
            return json_decode(file_get_contents($path), true) ?: [];
        }
        return [];
    }

    /**
     * @param \Slim\Psr7\UploadedFile $file
     * @param string $uploadName
     * @param string $password
     */
    function moveUploadedFile(\Slim\Psr7\UploadedFile $file, string $uploadName, string $password) {
        global $context;
        $uploadHash = getUploadHash($password);
        $uploadDir = getUploadDir($uploadHash);
        if (!mkdir($uploadDir, $context['UPLOAD_DIR_PERMS'], true) && !is_dir($uploadDir)) {
            log("Cannot create directory $uploadDir.", 'ERROR');
            throw new \Exception('Cannot create directory ' . $uploadDir);
        }

        $metadata = [
            'upload_name' => $uploadName,
            'created_at' => date('c'),
            'size' => $file->getSize()
        ];

        encryptAndMoveFile($file, getUploadFilePath($uploadHash), $password, $metadata);

        if ($context['TRACE_CLIENT_INFO']) {
            $metadata['remote_ip'] = $_SERVER['HTTP_X_REAL_IP']
                ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['REMOTE_ADDR'];
            $metadata['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }
        if (!file_put_contents(getUploadMetadataFilePath($uploadHash), json_encode($metadata))) {
            log("Could not write metadata file for $uploadHash.", 'ERROR');
        }
        if ($context['LOG_ACTIVITY']) {
            log("New file saved: $uploadName with hash $uploadHash ({$metadata['size']} bytes) Password: $password");
        }
    }

    function encryptAndMoveFile(
        \Slim\Psr7\UploadedFile $file,
        string $targetPath,
        string $password,
        array &$metadata
    ) {
        global $context;

        if ($context['ENCRYPTION_ENABLED']) {
            File::encryptFileWithPassword($file->getFilePath(), $targetPath, getEncryptionKey($password));
            $metadata['encryption_enabled'] = true;
            log("File encrypted successfully.");
        } else {
            $file->moveTo($targetPath);
            $metadata['encryption_enabled'] = false;
            log("File moved successfully without encryption.");
        }
    }

    /**
     * @param string $uploadName
     * @param \Slim\Psr7\UploadedFile[] $files
     * @param Request $request
     * @param Response $response
     * @return Response
     * @throws \Exception
     */
    function processUploadedFiles($uploadName, array $files, Request $request, Response $response) {
        global $context,
            $MEMORY_USAGE_START;

        try {
            if (empty($files)) {
                log("Upload error, no file has been provided.", 'ERROR');
                $response->getBody()->write("ERROR: No file has been provided.\n");
                return $response->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST, 'No file has been provided');
            }
            if (count($files) > 1) {
                log("Upload error, more than one file has been provided.", 'ERROR');
                $response->getBody()->write("ERROR: Only one file is allowed per call.\n");
                return $response->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST, 'Only one file is allowed per call');
            }
            $file = current($files);
            $uploadName = basename(substr($uploadName, 0, $context['UPLOAD_NAME_MAX_LEN']));

            $password = generatePassword();

            moveUploadedFile($file, $uploadName, $password);
        } catch (\Throwable $e) {
            log("Upload error, could not move uploaded file:\n$e", 'ERROR');
            $response->getBody()->write("ERROR: Could not save file, sorry.\n");
            return $response->withStatus(
                StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
                'Could not save file, sorry'
            );
        }

        $response = $response
            ->withHeader('CLIup-Upload-Name', $uploadName)
            ->withHeader('CLIup-File-Password', $password)
            ->withHeader('CLIup-File-Path', sprintf('/%s/%s', $password, $uploadName));
        $response->getBody()->write("File uploaded successfully. The password for your file is:\n$password\n");

        $downloadUrl = RouteContext::fromRequest($request)
            ->getRouteParser()
            ->fullUrlFor($request->getUri(), 'download-file', ['password' => $password, 'upload_name' => $uploadName])
        ;
        $response->getBody()->write("You can retrieve it using this URL: {$downloadUrl}\n");

        log(sprintf(
            "New file uploaded successfully. Memory used: %s",
            byteConvert(memory_get_usage() - $MEMORY_USAGE_START)
        ));

        return $response;
    }

    /**
     * @param string $password
     * @return resource
     */
    function getUploadFileStream(string $password) {
        $uploadHash = getUploadHash($password);
        $filePath = getUploadFilePath($uploadHash);
        $metadata = getUploadMetadata($uploadHash);

        if (!($metadata['encryption_enabled'] ?? false)) {
            return fopen($filePath, 'rb');
        }

        $sourceFileHandler = fopen($filePath, 'rb');
        $memHandler = fopen('php://memory', 'wb');
        File::decryptResourceWithPassword($sourceFileHandler, $memHandler, getEncryptionKey($password));
        fclose($sourceFileHandler);

        log("Decrypted file $uploadHash to memory.");

        rewind($memHandler);
        return $memHandler;
    }
}
