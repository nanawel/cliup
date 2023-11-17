<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$MEMORY_USAGE_START = memory_get_usage();
$MEMORY_USAGE_START_REAL = memory_get_usage(true);

require __DIR__ . '/vendor/autoload.php';

ini_set('xdebug.default_enable', false);
ini_set('html_errors', false);

$context = [
    'APP_BUILD_DATE'      => getenv('CLIUP_BUILD_DATE') ?: getenv('APP_BUILD_DATE') ?: 'unknown',
    'APP_VERSION'         => getenv('CLIUP_VERSION') ?: getenv('APP_VERSION') ?: 'dev',
    'BASE_PATH'           => getenv('BASE_PATH') ?: '',
    'BASE_URL'            => getenv('BASE_URL') ?: '',
    'DEBUG'               => getenv('DEBUG') ?: false,
    'ENCRYPTION_ENABLED'  => ((string) getenv('ENCRYPTION_ENABLED')) !== ''
        ? (bool) getenv('ENCRYPTION_ENABLED') : false,
    'EXPIRATION_TIME'     => getenv('EXPIRATION_TIME') ?: 60 * 60 * 24,                 // 1 DAY
    'HASH_SALT'           => getenv('HASH_SALT') ?: '',
    'PASS_WORDS_COUNT'    => min(10, getenv('PASS_WORDS_COUNT') ?: 3),
    'LOG_ACTIVITY'        => ((string) getenv('LOG_ACTIVITY')) !== ''
        ? (bool) getenv('LOG_ACTIVITY') : true,
    'MAX_UPLOAD_SIZE'     => getenv('MAX_UPLOAD_SIZE') ?: 1 * 1024 * 1024,              // 1 MB
    'MEMORY_LIMIT'        => getenv('MEMORY_LIMIT') ?: null,
    'TMP_DIR'             => getenv('TMP_DIR') ?: '/tmp',
    'TRACE_CLIENT_INFO'   => ((string) getenv('TRACE_CLIENT_INFO')) !== ''
        ? (bool) getenv('TRACE_CLIENT_INFO') : true,
    'UPLOAD_DIR'          => getenv('UPLOAD_DIR') ?: '/tmp',
    'UPLOAD_DIR_PERMS'    => octdec(getenv('UPLOAD_DIR_PERMS') ?: '0700'),
    'UPLOAD_NAME_MAX_LEN' => getenv('UPLOAD_NAME_MAX_LEN') ?: 255,
    'WORDSLIST_FILE'      => getenv('WORDSLIST_FILE') ?: __DIR__ . '/wordslist.txt',
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
