<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Force the test environment BEFORE Dotenv runs.
 *
 * Dotenv::bootEnv() decides which files to load from $_SERVER['APP_ENV'], and
 * only skips .env.local when it already knows the environment is a test one.
 * Left to itself it reads .env.local - which `make env` generates with
 * APP_ENV=dev - and the whole suite then runs against the development
 * container, cache and database.
 *
 * PHPUnit's <server name="APP_ENV" ...> is applied around the bootstrap rather
 * than strictly before it, so relying on it is a race. Setting it here is not.
 */
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '1';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env', 'test');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
