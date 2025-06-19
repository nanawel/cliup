<?php
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;

require __DIR__ . '/bootstrap.php';
global $context;

if (PHP_SAPI === 'cli') {
    $jsonContext = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents('php://stderr', "CLIup Configuration\n$jsonContext\n");

    exit(0);
}

// ============================================================================
//  APP INIT
// ============================================================================

$app = AppFactory::create();

$errorMiddleware = $app->addErrorMiddleware((bool) $context['DEBUG'], true, true);
$errorMiddleware->setErrorHandler(
    RuntimeException::class,
    function (
        \Psr\Http\Message\ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) {
        $response = new \Slim\Psr7\Response();
        switch (true) {
            case $exception instanceof \Slim\Exception\HttpException:
                $response->getBody()->write(sprintf(
                    "[%d] %s\n",
                    $exception->getCode(),
                    $exception->getMessage()
                ));
                $status = $exception->getCode();
                break;
            default:
                \CLiup\log($exception, 'ERROR');
                $response->getBody()->write("ERROR: Oops, got unexpected error! ¯\_(ツ)_/¯\n");
                $response->getBody()->write("       Try again?\n");
                $status = StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR;
                break;
        }

        return $response->withStatus($status);
    },
    true
);

if ($context['BASE_PATH']) {
    $app->setBasePath($context['BASE_PATH']);
}

// ============================================================================
//  MIDDLEWARES
// ============================================================================

// Proper handling of public URLs when behing a reverse-proxy
$app->add(function (Request $request, RequestHandlerInterface $handler) use ($app) {
    $uri = $request->getUri();

    // Scheme/proto
    $scheme = getenv('HTTP_FORWARDED_PROTO')
        ?: $request->getHeaderLine('X-Forwarded-Proto')
        ?: $uri->getScheme();
    if ($scheme !== $uri->getScheme()) {
        $uri = $uri->withScheme($scheme);
        $request = $request->withUri($uri);
    }

    // Host
    $host = getenv('HTTP_FORWARDED_HOST')
        ?: $request->getHeaderLine('X-Forwarded-Host')
        ?: $uri->getHost();
    if ($host !== $uri->getHost()) {
        $uri = $uri->withHost($host);
        $request = $request->withUri($uri);
    }

    // Port
    $port = (int) (getenv('HTTP_FORWARDED_PORT')
        ?: $request->getHeaderLine('X-Forwarded-Port')
        ?: $uri->getPort());
    if ($port !== $uri->getPort()) {
        $uri = $uri->withPort($port);
        $request = $request->withUri($uri);
    }

    return $handler->handle($request);
});

// ============================================================================
//  ROUTES
// ============================================================================

$app->map(['HEAD'], '/', function (Request $request, Response $response, $args) use ($context) {
    $response = $response
        ->withStatus(StatusCodeInterface::STATUS_NO_CONTENT)
        ->withHeader('CLIup-Version', $context['APP_VERSION'])
        ->withHeader('CLIup-Expiration-Time', $context['EXPIRATION_TIME'])
        ->withHeader('CLIup-Max-Upload-Size', $context['MAX_UPLOAD_SIZE'])
        ->withHeader('CLIup-Pass-Words-Count', $context['PASS_WORDS_COUNT'])
        ->withHeader('Content-Type', 'text/plain')
    ;

    return $response;
});

// Special handler for /favicon.ico to prevent unnecessary log pollution
$app->get('/favicon.ico', function (Request $request, Response $response, $args) use ($context) {
    return $response->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);
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

    $baseUrl = rtrim(
        RouteContext::fromRequest($request)->getRouteParser()->fullUrlFor($request->getUri(), 'index'),
        '/'
    );

    $response = $response->withHeader('Content-Type', 'text/plain');
    $response->getBody()->write(<<<"EOT"
  _______   ____        
 / ___/ /  /  _/_ _____ 
/ /__/ /___/ // // / _ \
\___/____/___/\_,_/ .__/    <https://github.com/nanawel/cliup>
                 /_/ 
CLIup version {$context['APP_VERSION']} (built: {$context['APP_BUILD_DATE']})
Please use a PUT or a POST request to send a file.

Maximum file size    : $maxFilesizeHuman ({$context['MAX_UPLOAD_SIZE']} bytes)
Maximum file lifetime: $expirationTimeHuman

** Examples with cURL **
Simple (using PUT):
    curl -T myfile.txt $baseUrl
Alternative (using POST):
    curl -F 'data=@myfile.txt' $baseUrl/myfile.txt

EOT);

    if ($context['DEBUG']) {
        $response->getBody()->write("\n\n** DEBUG **\n" . json_encode([
            'env' => getenv(),
            '$_SERVER' => $_SERVER,
            'ini' => ini_get_all()
        ], JSON_PRETTY_PRINT) . "\n");
    }

    return $response;
})->setName('index');

$app->get('/{password}[/{upload_name}]', function (Request $request, Response $response, $args) {
    global $context,
           $MEMORY_USAGE_START;

    $uploadHash = \CLiup\getUploadHash($args['password']);
    $filePath = \CLiup\getUploadFilePath($uploadHash);
    $metadataFilePath = \CLiup\getUploadMetadataFilePath($uploadHash);

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

        $metadata = \CLiup\getUploadMetadata($uploadHash);
        $uploadName = basename($args['upload_name'] ?? $metadata['upload_name'] ?? ('cliup-file-' . date('YmdHisv')));

        $response = $response
            ->withHeader('Content-Type', mime_content_type($filePath) ?: 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename=' . $uploadName)
            ->withAddedHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Cache-Control', 'post-check=0, pre-check=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('CLIup-Upload-Expiration', (date('c', $filemtime + $context['EXPIRATION_TIME'])))
            ->withBody((new \Slim\Psr7\Stream(\CLiup\getUploadFileStream($args['password']))))
        ;
    } catch (\Throwable $e) {
        \CLiup\log(
            sprintf(
                "Got exception while trying to access the file %s with password %s:\n%s",
                $uploadHash,
                \CLiup\getPasswordForLog($args['password']),
                $e
            ),
            'ERROR'
        );
        $response->getBody()->write("ERROR: No file found with that password, or it has expired.\n");
        return $response->withStatus(
            StatusCodeInterface::STATUS_NOT_FOUND,
            'No file found with that password, or it has expired'
        );
    }

    \CLiup\log(sprintf(
        "Sending file %s with password %s (%s). Memory used: %s",
        $uploadHash,
        \CLiup\getPasswordForLog($args['password']),
        $uploadName,
        byteConvert(memory_get_usage() - $MEMORY_USAGE_START)
    ));

    return $response;
})->setName('download-file');

$app->delete('/{password}', function (Request $request, Response $response, $args) {
    global $context;

    try {
        $uploadHash = \CLiup\getUploadHash($args['password']);
        $filePath = \CLiup\getUploadFilePath($uploadHash);
        $metadataFilePath = \CLiup\getUploadMetadataFilePath($uploadHash);

        if (!is_file($filePath)) {
            throw new \Exception('File not found');
        }

        $filemtime = filemtime($filePath);
        if (time() > ($filemtime + $context['EXPIRATION_TIME'])) {
            @unlink($filePath);
            @unlink($metadataFilePath);

            throw new \Exception('Expired');
        }

        if (is_file($filePath)) {
            $success = @unlink($filePath);
            if (is_file($metadataFilePath)) {
                $success &= @unlink($metadataFilePath);
            }
        }
    }
    catch (\Throwable $e) {
        \CLiup\log(
            sprintf(
                "Got exception while trying to delete the file %s with password %s:\n%s",
                $uploadHash,
                \CLiup\getPasswordForLog($args['password']),
                $e
            ),
            'ERROR'
        );
        $response->getBody()->write("ERROR: No file found with that password, or it has expired.\n");
        return $response->withStatus(
            StatusCodeInterface::STATUS_NOT_FOUND,
            'No file found with that password, or it has expired'
        );
    }


    if ($success) {
        \CLiup\log(sprintf(
            "File %s with password %s has been deleted.",
            $uploadHash,
            \CLiup\getPasswordForLog($args['password'])
        ));
        $response->getBody()->write("OK, the file has been deleted.\n");
    } else {
        \CLiup\log(
            sprintf(
                "File %s with password %s could not be deleted.",
                $uploadHash,
                \CLiup\getPasswordForLog($args['password'])
            ),
            'ERROR'
        );
        $response->getBody()->write("ERROR: Sorry, unable to delete the file.\n");
        return $response->withStatus(
            StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
            'Sorry, unable to delete the file'
        );
    }

    return $response;
});

$rootHandler = function (Request $request, Response $response, $args) {
    $response->getBody()->write("ERROR: Please specify the name of your upload using the path. Ex: /myupload.gif\n");
    return $response->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST);
};
$app->post('/', $rootHandler);
$app->put('/', $rootHandler);

$app->post('/{uploadname}', function (Request $request, Response $response, $args) {
    /** @var \Slim\Psr7\UploadedFile[] $files */
    $files = $request->getUploadedFiles();

    $fileObjects = [];
    array_walk_recursive($files, function ($it) use (&$fileObjects) {
        if ($it instanceof \Slim\Psr7\UploadedFile) {
            $fileObjects[] = $it;
        }
    });

    $response = \CLiup\processUploadedFiles($args['uploadname'], $fileObjects, $request, $response);

    return $response;
});

$app->put('/{uploadname}', function (Request $request, Response $response, $args) {
    global $context;
    $fileContent = $request->getBody()->getContents();
    if (($filesize = strlen($fileContent)) > $context['MAX_UPLOAD_SIZE']) {
        \CLiup\log("File is too big: $filesize bytes (limit = {$context['MAX_UPLOAD_SIZE']} bytes).", 'ERROR');
        $response->getBody()->write("ERROR: File is too big!\n");
        return $response->withStatus(StatusCodeInterface::STATUS_PAYLOAD_TOO_LARGE, 'File is too big!');
    }
    if (!file_put_contents($file = $context['TMP_DIR'] . '/' . uniqid('CLIUP_tmp_'), $fileContent)) {
        \CLiup\log("Could not write file data to $file (filesize = $filesize).", 'ERROR');
        $response->getBody()->write("ERROR: Upload has failed.\n");
        return $response->withStatus(StatusCodeInterface::STATUS_BAD_REQUEST, 'Upload has failed');
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
