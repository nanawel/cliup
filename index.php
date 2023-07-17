<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/bootstrap.php';
global $context;

if (PHP_SAPI === 'cli') {
    $jsonContext = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents('php://stderr', "CLIup Configuration\n$jsonContext\n");

    exit(0);
}

$app = AppFactory::create();

if ($context['BASE_URL']) {
    $app->setBasePath($context['BASE_URL']);
}


$app->map(['HEAD'], '/', function (Request $request, Response $response, $args) use ($context) {
    $response = $response
        ->withHeader('CLIup-Version', $context['APP_VERSION'])
        ->withHeader('CLIup-Expiration-Time', $context['EXPIRATION_TIME'])
        ->withHeader('CLIup-Max-Upload-Size', $context['MAX_UPLOAD_SIZE'])
        ->withHeader('CLIup-Pass-Words-Count', $context['PASS_WORDS_COUNT'])
        ->withHeader('Content-Type', 'text/plain')
    ;

    return $response;
});

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
CLIup version {$context['APP_VERSION']}
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

$app->get('/{password}[/{upload_name}]', function (Request $request, Response $response, $args) {
    global $context;

    $uploadHash = \CLiup\getUploadHash($args['password']);
    $filePath = \CLiup\getUploadFilePath($uploadHash);
    $metadataFilePath = \CLiup\getUploadMetdataFilePath($uploadHash);

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
        \CLiup\log("No file found with password {$args['password']}: {$e->getMessage()}.", 'ERROR');
        $response->getBody()->write("ERROR: No file found with that password, or it has expired.\n");
        return $response->withStatus(404, 'No file found with that password, or it has expired');
    }

    if (is_file($metadataFilePath)) {
        $metadata = json_decode(file_get_contents($metadataFilePath), true) ?: [];
    } else {
        $metadata = [];
    }
    $uploadName = basename($args['upload_name'] ?? $metadata['upload_name'] ?? 'file');

    $response = $response
        ->withHeader('Content-Type', mime_content_type($filePath) ?: 'application/octet-stream')
        ->withHeader('Content-Disposition', 'attachment; filename=' . $uploadName)
        ->withAddedHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->withHeader('Cache-Control', 'post-check=0, pre-check=0')
        ->withHeader('Pragma', 'no-cache')
        ->withHeader('CLIup-Upload-Expiration', (date('c', $filemtime + $context['EXPIRATION_TIME'])))
        ->withBody((new \Slim\Psr7\Stream(fopen($filePath, 'rb'))));

    \CLiup\log("Sending file $uploadHash with password {$args['password']} ($uploadName).");

    return $response;
});

$app->delete('/{password}', function (Request $request, Response $response, $args) {
    global $context;

    $uploadHash = \CLiup\getUploadHash($args['password']);
    $filePath = \CLiup\getUploadFilePath($uploadHash);
    $metadataFilePath = \CLiup\getUploadMetdataFilePath($uploadHash);

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
        \CLiup\log("No file found with password {$args['password']}: {$e->getMessage()}.", 'ERROR');
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
        \CLiup\log("File $uploadHash with password {$args['password']} has been deleted.");
        $response->getBody()->write("OK, the file has been deleted.\n");
    } else {
        \CLiup\log("File $uploadHash with password {$args['password']} could not be deleted.", 'ERROR');
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

    $response = \CLiup\processUploadedFiles($args['uploadname'], $fileObjects, $request, $response);

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

    $response = \CLiup\processUploadedFiles(
        $args['uploadname'],
        [new \Slim\Psr7\UploadedFile($file, $args['uploadname'], null, filesize($file))],
        $request,
        $response
    );

    return $response;
});

$app->run();
