<?php

declare(strict_types=1);

use App\Application;
use App\Config;
use App\ConfigurationException;
use App\FreeScout;
use App\RateLimiter;
use App\Request;
use Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    try {
        Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    } catch (\Dotenv\Exception\InvalidFileException $exception) {
        throw new ConfigurationException('Invalid .env syntax. Check quoting and KEY=value formatting.', 0, $exception);
    } catch (\Dotenv\Exception\InvalidPathException $exception) {
        throw new ConfigurationException('Unable to read .env. Check file permissions.', 0, $exception);
    }

    $config = new Config(array_merge($_SERVER, $_ENV, getenv()));
    $application = new Application(
        $config,
        new RateLimiter(dirname(__DIR__).'/storage/rate-limits.json', $config->rateLimitSeconds),
        new FreeScout($config),
    );

    $application->handle(Request::capture());
} catch (Throwable $exception) {
    error_log('Application error: '.$exception->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['message' => $exception instanceof ConfigurationException
        ? 'Configuration error: '.$exception->getMessage()
        : 'Unexpected error processing your request. Check the PHP error log for details.'], JSON_INVALID_UTF8_SUBSTITUTE);
}
