<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

ini_set('xdebug.default_enable', false);
ini_set('html_errors', false);

$app = AppFactory::create();

$context = [
    'APP_VERSION'       => getenv('CLIUP_VERSION') ?: '(dev)',
    'DEBUG'             => getenv('DEBUG') ?: false,
    'EXPIRATION_TIME'   => getenv('EXPIRATION_TIME') ?: 60 * 60 * 24,                 // 1 DAY
    'HASH_SALT'         => getenv('HASH_SALT') ?: '',
    'PASS_WORDS_COUNT'  => min(10, getenv('PASS_WORDS_COUNT') ?: 3),
    'MAX_UPLOAD_SIZE'   => getenv('MAX_UPLOAD_SIZE') ?: 1 * 1024 * 1024,              // 1 MB
    'MEMORY_LIMIT'      => getenv('MEMORY_LIMIT') ?: null,
    'TMP_DIR'           => getenv('TMP_DIR') ?: '/tmp',
    'UPLOAD_DIR'        => getenv('UPLOAD_DIR') ?: '/tmp',
    'UPLOAD_DIR_PERMS'  => getenv('UPLOAD_DIR_PERMS') ?: 0700,
    'WORDSLIST_FILE'    => getenv('WORDSLIST_FILE') ?: __DIR__ . '/wordslist.txt',
];
ini_set('upload_max_filesize', $context['MAX_UPLOAD_SIZE']);
ini_set('post_max_size', $context['MAX_UPLOAD_SIZE']);
ini_set('upload_tmp_dir', $context['TMP_DIR']);

if ($context['MEMORY_LIMIT']) {
    ini_set('memory_limit', $context['MEMORY_LIMIT']);
}

if (!is_file($context['WORDSLIST_FILE']) || !is_readable($context['WORDSLIST_FILE'])) {
    throw new \Exception($context['WORDSLIST_FILE'] . ' is not readable!');
}

if (!is_dir($context['UPLOAD_DIR']) || !is_writable($context['UPLOAD_DIR'])) {
    throw new \Exception($context['UPLOAD_DIR'] . ' is not writable!');
}

if (PHP_SAPI === 'cli') {
    $jsonContext = json_encode($context, JSON_PRETTY_PRINT);
    file_put_contents('php://stderr', "CLIup Configuration\n$jsonContext\n");

    exit(0);
}

function generatePassword() {
    global $context;
    $dictFile = new SplFileObject($context['WORDSLIST_FILE']);
    $passwords = [];
    $dictFile->seek(PHP_INT_MAX);
    $lineCnt = $dictFile->key();
    for ($i = 0; $i < $context['PASS_WORDS_COUNT']; $i++) {
        $dictFile->rewind();
        $dictFile->seek(random_int(1, $lineCnt));
        $passwords[] = trim($dictFile->fgets());
    }

    return implode('-', $passwords);
}

function getUploadHash(string $password) {
    global $context;
    return sha1($context['HASH_SALT'] . $password);
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

function getUploadMetdataFilePath(string $uploadHash) {
    return getUploadDir($uploadHash) . "/$uploadHash.json";
}

/**
 * @param \Psr\Http\Message\UploadedFileInterface $file
 * @param string $uploadName
 * @param string $password
 * @return
 */
function moveUploadedFile(\Psr\Http\Message\UploadedFileInterface $file, $uploadName, $password) {
    global $context;
    $uploadHash = getUploadHash($password);
    $uploadDir = getUploadDir($uploadHash);
    if (!mkdir($uploadDir, $context['UPLOAD_DIR_PERMS'], true) && !is_dir($uploadDir)) {
        throw new \Exception('Cannot create directory ' . $uploadDir);
    }
    $file->moveTo(getUploadFilePath($uploadHash));
    file_put_contents(getUploadMetdataFilePath($uploadHash), json_encode([
        'remote_ip' => $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        'upload_name' => $uploadName,
        'created_at' => date('c'),
        'size' => $file->getSize()
    ]));
}

/**
 * @param string $uploadName
 * @param \Psr\Http\Message\UploadedFileInterface[] $files
 * @param Request $request
 * @param Response $response
 * @return Response
 * @throws Exception
 */
function processUploadedFiles($uploadName, array $files, Request $request, Response $response) {
    if (empty($files)) {
        $response->getBody()->write("ERROR: No file has been provided.\n");
        return $response->withStatus(400, 'No file has been provided');
    }
    if (count($files) > 1) {
        $response->getBody()->write("ERROR: Only one file is allowed per call.\n");
        return $response->withStatus(400, 'Only one file is allowed per call');
    }
    $file = current($files);

    $password = generatePassword();
    try {
        moveUploadedFile($file, $uploadName, $password);
    } catch (\Throwable $e) {
        $response->getBody()->write("ERROR: Could not save file, sorry.\n");
        return $response->withStatus(500, 'Could not save file, sorry');
    }

    $response = $response
        ->withHeader('CLIup-Upload-Name', $uploadName)
        ->withHeader('CLIup-File-Password', $password)
        ->withHeader('CLIup-File-Path', sprintf('q/%s/%s', $password, $uploadName));
    $response->getBody()->write("OK, the password for your file is: $password\n");

    return $response;
}

$app->get('/', function (Request $request, Response $response, $args) use ($context) {
    $maxFilesizeHuman = byteConvert($context['MAX_UPLOAD_SIZE']);
    $expirationTimeHuman = human_date_interval(
        new \DateInterval('PT' . ($context['EXPIRATION_TIME']) . 'S'),
        [
            'months' => '%d month(s)',
            'days' => '%d day(s)',
            'hours' => '%d hour(s)',
            'minutes' => '%d minute(s)',
        ]
    );

    $response = $response->withHeader('Content-Type', 'text/plain');
    $response->getBody()->write(<<<"EOT"
  _______   ____        
 / ___/ /  /  _/_ _____ 
/ /__/ /___/ // // / _ \
\___/____/___/\_,_/ .__/
                 /_/ 
CLIup v{$context['APP_VERSION']}
Please use a PUT or a POST request to send a file.

Maximum file size    : $maxFilesizeHuman ({$context['MAX_UPLOAD_SIZE']} bytes)
Maximum file lifetime: $expirationTimeHuman

** Examples with cURL **
Simple (using PUT):
    curl -T myfile.txt https://this.domain
Alternative (using POST):
    curl -F 'data=@myfile.txt' https://this.domain/myfile.txt

EOT);

    if ($context['DEBUG']) {
        $response->getBody()->write("\n\n** DEBUG **\n" . json_encode([
            'env' => getenv(),
            '$_SERVER' => $_SERVER,
            'ini' => ini_get_all()
        ], JSON_PRETTY_PRINT) . "\n");
    }

    return $response;
});

$app->get('/{password}', function (Request $request, Response $response, $args) {
    global $context;

    $uploadHash = getUploadHash($args['password']);
    $filePath = getUploadFilePath($uploadHash);
    $metadataFilePath = getUploadMetdataFilePath($uploadHash);

    try {
        if (!is_file($filePath)) {
            throw new \Exception('File not found');
        }

        $filemtime = filemtime($filePath);
        if (time() > ($filemtime + $context['EXPIRATION_TIME'])) {
            @unlink($filePath);
            @unlink($metadataFilePath);

            throw new \Exception('Expired');
        }
    }
    catch (\Throwable $e) {
        $response->getBody()->write("ERROR: No file found with that password, or it has expired.\n");
        return $response->withStatus(404, 'No file found with that password, or it has expired');
    }

    if (is_file($metadataFilePath)) {
        $metadata = json_decode(file_get_contents($metadataFilePath), true) ?: [];
    } else {
        $metadata = [];
    }

    $response = $response
        ->withHeader('Content-Type', mime_content_type($filePath) ?: 'application/octet-stream')
        ->withHeader('Content-Disposition', 'attachment; filename=' . ($metadata['upload_name'] ?? 'file'))
        ->withAddedHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->withHeader('Cache-Control', 'post-check=0, pre-check=0')
        ->withHeader('Pragma', 'no-cache')
        ->withHeader('CLIup-Upload-Expiration', (date('c', $filemtime + $context['EXPIRATION_TIME'])))
        ->withBody((new \Slim\Psr7\Stream(fopen($filePath, 'rb'))));

    return $response;
});

$app->delete('/{password}', function (Request $request, Response $response, $args) {
    global $context;

    $uploadHash = getUploadHash($args['password']);
    $filePath = getUploadFilePath($uploadHash);
    $metadataFilePath = getUploadMetdataFilePath($uploadHash);

    try {
        if (!is_file($filePath)) {
            throw new \Exception('File not found');
        }

        $filemtime = filemtime($filePath);
        if (time() > ($filemtime + $context['EXPIRATION_TIME'])) {
            @unlink($filePath);
            @unlink($metadataFilePath);

            throw new \Exception('Expired');
        }
    }
    catch (\Throwable $e) {
        $response->getBody()->write("ERROR: No file found with that password, or it has expired.\n");
        return $response->withStatus(404, 'No file found with that password, or it has expired');
    }

    if (is_file($filePath)) {
        $success = @unlink($filePath);
        if (is_file($metadataFilePath)) {
            $success &= @unlink($metadataFilePath);
        }
    }

    if ($success) {
        $response->getBody()->write("OK, the file has been deleted.\n");
    } else {
        $response->getBody()->write("ERROR: Sorry, unable to delete the file.\n");
        return $response->withStatus(500, 'Sorry, unable to delete the file');
    }

    return $response;
});

$rootHandler = function (Request $request, Response $response, $args) {
    $response->getBody()->write("ERROR: Please specify the name of your upload using the path. Ex: /myupload.gif\n");
    return $response->withStatus(400);
};
$app->post('/', $rootHandler);
$app->put('/', $rootHandler);

$app->post('/{uploadname}', function (Request $request, Response $response, $args) {
    /** @var \Psr\Http\Message\UploadedFileInterface[] $files */
    $files = $request->getUploadedFiles();

    $fileObjects = [];
    array_walk_recursive($files, function ($it) use (&$fileObjects) {
        if ($it instanceof \Psr\Http\Message\UploadedFileInterface) {
            $fileObjects[] = $it;
        }
    });

    $response = processUploadedFiles($args['uploadname'], $fileObjects, $request, $response);

    return $response;
});

$app->put('/{uploadname}', function (Request $request, Response $response, $args) {
    global $context;
    $fileContent = $request->getBody()->getContents();
    if (strlen($fileContent) > $context['MAX_UPLOAD_SIZE']) {
        $response->getBody()->write("ERROR: File is too big!\n");
        return $response->withStatus(400, 'File is too big!');
    }
    if (!file_put_contents($file = $context['TMP_DIR'] . '/' . uniqid('CLIUP_tmp_'), $fileContent)) {
        $response->getBody()->write("ERROR: Upload has failed.\n");
        return $response->withStatus(400, 'Upload has failed');
    }

    $response = processUploadedFiles(
        $args['uploadname'],
        [new \Slim\Psr7\UploadedFile($file, $args['uploadname'], null, filesize($file))],
        $request,
        $response
    );

    return $response;
});

$app->run();
