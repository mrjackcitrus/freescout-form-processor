<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Limit submissions to one request per company and client IP per interval.
 */
final class RateLimiter
{
    /**
     * Create a new file-backed rate limiter.
     */
    public function __construct(
        private readonly string $path,
        private readonly int $intervalSeconds = 5,
    ) {}

    /**
     * Consume a request allowance and return its remaining quota and reset time.
     *
     * @return array{allowed: bool, reset: int}
     */
    public function hit(string $slug, string $ip): array
    {
        $file = fopen($this->path, 'c+');

        if ($file === false) {
            throw new RuntimeException('Unable to open rate limit storage.');
        }

        try {
            if (! flock($file, LOCK_EX)) {
                throw new RuntimeException('Unable to lock rate limit storage.');
            }

            $now = microtime(true);
            $entries = json_decode(stream_get_contents($file), true) ?? [];
            foreach ($entries as $entry => $expiry) {
                if ($expiry <= $now) {
                    unset($entries[$entry]);
                }
            }
            $key = hash('sha256', $slug.':'.$ip);
            $allowed = ! isset($entries[$key]);

            if ($allowed) {
                $entries[$key] = $now + $this->intervalSeconds;
            }

            $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
            rewind($file);
            if (! ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || ! fflush($file)) {
                throw new RuntimeException('Unable to persist rate limit storage.');
            }

            return ['allowed' => $allowed, 'reset' => (int) ceil($entries[$key] - $now)];
        } finally {
            fclose($file);
        }
    }
}
