<?php

/**
 * Environment bootstrap.
 *
 * Locally the application reads its configuration from the .env file. On a
 * serverless platform (Vercel) there is no .env file: the values arrive as
 * real environment variables, so they are copied into $_ENV to keep the rest
 * of the codebase working unchanged.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

foreach (getenv() as $name => $value) {
    if (!array_key_exists($name, $_ENV)) {
        $_ENV[$name] = $value;
    }
}
