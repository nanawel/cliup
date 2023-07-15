<?php

require __DIR__ . '/bootstrap.php';
global $context;

switch ($argv[1] ?? null) {
    case 'purge':
        $expirationTimeInMinutes = round($context['EXPIRATION_TIME'] / 60);
        echo "Purging expired files...\n";
        system(
            $cmd = sprintf(
                'find %s -path ??/??/* -type f -mmin %d -delete',
                escapeshellarg($context['UPLOAD_DIR']),
                $expirationTimeInMinutes
            ),
            $rc
        );
        if ($rc > 0) {
            echo "find+delete failed with error $rc :(\n";
            exit(2);
        }
        //echo "Command:\n\t$cmd\n";
        break;

    default:
        echo "Invalid or missing command.\n";
        exit(1);
}
