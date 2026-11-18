<?php

declare(strict_types=1);

namespace App;

/**
 * Resolve FreeScout credentials and company-specific configuration.
 */
final class Config
{
    public readonly string $url;

    public readonly string $key;

    public readonly array $trustedProxies;

    public readonly int $rateLimitSeconds;

    public readonly int $minSubmitSeconds;

    public readonly int $minMessageLength;

    public readonly array $spamKeywords;

    private array $mailboxes = [];

    private array $origins = [];

    /**
     * Create a new configuration instance from environment variables.
     */
    public function __construct(array $env)
    {
        $this->url = trim($env['FREESCOUT_API_URL'] ?? '');
        $this->key = trim($env['FREESCOUT_API_KEY'] ?? '');
        $this->trustedProxies = self::split($env['TRUSTED_PROXIES'] ?? '');
        $this->rateLimitSeconds = self::integer($env, 'RATE_LIMIT_SECONDS', 5, 1);
        $this->minSubmitSeconds = self::integer($env, 'MIN_SUBMIT_SECONDS', 5, 0);
        $this->minMessageLength = self::integer($env, 'MIN_MESSAGE_LENGTH', 5, 1);
        if ($this->minMessageLength > 5000) {
            throw new ConfigurationException('MIN_MESSAGE_LENGTH must not exceed 5000 characters.');
        }
        $this->spamKeywords = self::split($env['SPAM_KEYWORDS'] ?? 'viagra,cialis,loan approval,crypto');

        $missing = [];
        if ($this->url === '') {
            $missing[] = 'FREESCOUT_API_URL';
        }
        if ($this->key === '') {
            $missing[] = 'FREESCOUT_API_KEY';
        }
        if ($missing !== []) {
            throw new ConfigurationException('Missing or empty environment variables: '.implode(', ', $missing).'.');
        }

        foreach ($env as $name => $value) {
            if (str_starts_with($name, 'COMPANY_MAILBOX_ID_')) {
                $slug = self::normalizeSlug(substr($name, strlen('COMPANY_MAILBOX_ID_')));
                $id = filter_var($value, FILTER_VALIDATE_INT);

                if ($slug !== '' && $id !== false && $id > 0) {
                    $this->mailboxes[$slug] = $id;
                }
            } elseif (str_starts_with($name, 'COMPANY_ALLOWED_ORIGIN_')) {
                $slug = self::normalizeSlug(substr($name, strlen('COMPANY_ALLOWED_ORIGIN_')));
                $this->origins[$slug] = self::split($value);
            }
        }
    }

    /**
     * Normalize a company slug to lowercase ASCII alphanumeric characters.
     */
    public static function normalizeSlug(string $slug): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($slug));
    }

    /**
     * Retrieve the mailbox assigned to the given company.
     */
    public function mailbox(string $slug): ?int
    {
        return $this->mailboxes[$slug] ?? null;
    }

    /**
     * Get the origins permitted to access the company endpoint.
     *
     * @return list<string>
     */
    public function origins(string $slug): array
    {
        return $this->origins[$slug] ?? [];
    }

    /**
     * Split a comma-separated configuration value into non-empty entries.
     */
    private static function split(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }

    private static function integer(array $env, string $name, int $default, int $minimum): int
    {
        $value = filter_var($env[$name] ?? $default, FILTER_VALIDATE_INT);

        if ($value === false || $value < $minimum) {
            throw new ConfigurationException("{$name} must be an integer greater than or equal to {$minimum}.");
        }

        return $value;
    }
}
