<?php

require __DIR__ . '/bootstrap.php';
global $context;

switch ($argv[1] ?? null) {
    case 'purge':
        if (!$expirationTimeInMinutes = round($context['EXPIRATION_TIME'] / 60)) {
            echo "EXPIRATION_TIME is not set. No purge required.\n";
            break;
        }
        chdir($context['UPLOAD_DIR']);
        $cmd = sprintf(
            'find . -path "./??/??/*" -type f -mmin +%d -delete',
            $expirationTimeInMinutes
        );

        echo "Purging expired files using the following command:\n";
        echo "\t$cmd\n";
        system($cmd, $rc);
        if ($rc > 0) {
            echo "find+delete failed with error $rc :(\n";
            exit(2);
        }
        echo "Purge complete.\n";
        break;

    default:
        echo "Invalid or missing command.\n";
        exit(1);
}
