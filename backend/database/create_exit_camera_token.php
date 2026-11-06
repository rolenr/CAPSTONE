<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$path = __DIR__ . '/exit_camera_token';
if (is_file($path)) {
    echo "Exit camera token already exists.\n";
    exit;
}
$handle = @fopen($path, 'x');
if ($handle === false) {
    fwrite(STDERR, "Could not create the exit camera token.\n");
    exit(1);
}
chmod($path, 0600);
fwrite($handle, bin2hex(random_bytes(32)) . "\n");
fclose($handle);
echo "Exit camera token created. Its value was not printed.\n";
