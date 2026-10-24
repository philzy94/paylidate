<?php

require __DIR__ . '/../vendor/autoload.php';

// A throwaway encryption key per test run, so no key is stored in the repository.
if (!getenv('APP_KEY')) {
    $key = 'base64:' . base64_encode(random_bytes(32));
    putenv("APP_KEY={$key}");
    $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $key;
}
