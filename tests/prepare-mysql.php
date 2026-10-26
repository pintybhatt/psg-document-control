<?php

/** Prepare private test-only secrets and check the fixed loopback port before Compose starts. */
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI use only.');
}

$socket = @stream_socket_server('tcp://127.0.0.1:33084', $errorNumber, $errorMessage);
if ($socket === false) {
    throw new RuntimeException('Cannot bind 127.0.0.1:33084 (error '.$errorNumber.'). Check port availability and socket permissions; do not replace another service.');
}
fclose($socket);

$directory = __DIR__.'/../storage/framework/testing/mysql84';
if (! is_dir($directory)) {
    mkdir($directory, 0700, true);
}
chmod($directory, 0700);
foreach (['password', 'root-password'] as $name) {
    $path = $directory.'/'.$name;
    if (! file_exists($path)) {
        $file = fopen($path, 'x');
        chmod($path, 0600);
        fwrite($file, bin2hex(random_bytes(32)).PHP_EOL);
        fclose($file);
    }
    if (! preg_match('/^[a-f0-9]{64}$/D', trim(file_get_contents($path)))) {
        throw new RuntimeException('Unexpected secret format; existing credentials were preserved.');
    }
    chmod($path, 0600);
}
echo "Private test credentials prepared; loopback port 33084 is available.\n";
