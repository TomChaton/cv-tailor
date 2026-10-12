<?php

// Tests run without a deployment's environment, so nothing here depends on how
// this machine happens to be configured. The encrypter needs an application key
// and does not care which one, so each run makes its own rather than the
// repository carrying a key that looks like a secret.
$_SERVER['APP_KEY'] = $_ENV['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));

require __DIR__.'/../vendor/autoload.php';
