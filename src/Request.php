<?php

declare(strict_types=1);

namespace App;

use JsonException;
use InvalidArgumentException;

/**
 * Provide access to incoming HTTP request data.
 */
final class Request
{
    /**
     * Create a new request instance.
     */
    public function __construct(private readonly array $server, private readonly array $form, private readonly string $body) {}

    /**
     * Capture the current HTTP request.
     */
    public static function capture(): self
    {
        return new self($_SERVER, $_POST, file_get_contents('php://input'));
    }

    /**
     * Get the HTTP request method.
     */
    public function method(): string
    {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Get the path without its query string.
     */
    public function path(): string
    {
        return explode('?', $this->server['REQUEST_URI'] ?? '/', 2)[0];
    }

    /**
     * Retrieve an incoming HTTP header.
     */
    public function header(string $name): string
    {
        $key = str_replace('-', '_', strtoupper($name));

        return $this->server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] ?? '';
    }

    /**
     * Decode a JSON or URL-encoded form submission.
     *
     * @throws InvalidArgumentException
     */
    public function data(): array
    {
        if (strlen($this->body) > 102400) {
            throw new InvalidArgumentException('Request body is too large.', 413);
        }

        $type = strtolower(trim(explode(';', $this->header('Content-Type'))[0]));

        if ($type === 'application/json' && $this->body !== '') {
            try {
                $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Invalid JSON.', 400, $exception);
            }

            if (! is_array($data)) {
                throw new InvalidArgumentException('Invalid JSON body.', 400);
            }

            return $data;
        }

        return $type === 'application/x-www-form-urlencoded' ? $this->form : [];
    }

    /**
     * Resolve the client IP, honoring forwarded addresses from trusted proxies only.
     */
    public function ip(array $trustedProxies): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? 'unknown';
        $chain = array_reverse(array_map('trim', explode(',', $this->header('X-Forwarded-For'))));

        foreach ($chain as $address) {
            if (! in_array($ip, $trustedProxies, true) || ! filter_var($address, FILTER_VALIDATE_IP)) {
                break;
            }

            $ip = $address;
        }

        return $ip;
    }
}
