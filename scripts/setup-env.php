<?php

$root = dirname(__DIR__);
$file = $root.'/.env';
if (! file_exists($file)) {
    copy($root.'/.env.example', $file);
}
$contents = file_get_contents($file);
foreach (['APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'DB_PASSWORD' => bin2hex(random_bytes(24)), 'REDIS_PASSWORD' => bin2hex(random_bytes(24))] as $key => $value) {
    $contents = preg_replace('/^'.preg_quote($key, '/').'=$/m', $key.'='.$value, $contents);
}
file_put_contents($file, $contents);
chmod($file, 0600);
echo "Local environment ready. Existing values were preserved.\n";
