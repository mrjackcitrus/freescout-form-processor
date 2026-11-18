<?php

declare(strict_types=1);

namespace App;

/**
 * Sanitize contact form input and detect basic spam submissions.
 */
final class Validation
{
    /**
     * Determine whether the email matches the original service's format rules.
     */
    public static function isValidEmail(string $email): bool
    {
        return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/uD', $email) === 1;
    }

    /**
     * Remove null bytes, trim whitespace and truncate a string safely.
     */
    public static function sanitize(mixed $value, int $maxLength = 500): string
    {
        return is_string($value)
            ? mb_substr(trim(str_replace("\0", '', $value)), 0, $maxLength, 'UTF-8')
            : '';
    }

    /**
     * Return the first detected spam violation, or null for a valid submission.
     */
    public static function spamError(
        array $data,
        int $minSubmitSeconds = 5,
        array $spamKeywords = ['viagra', 'cialis', 'loan approval', 'crypto'],
    ): ?string
    {
        $website = $data['website'] ?? '';
        $timestamp = $data['formTs'] ?? '';
        $message = $data['message'] ?? '';

        // Honey pot.
        if (! is_scalar($website) || trim((string) $website) !== '') {
            return 'Spam detected (type 1).';
        }
        // Timestamp missing.
        if (! is_numeric($timestamp) || (float) $timestamp <= 0) {
            return 'Spam detected (type 2).';
        }
        // Too quick.
        if (microtime(true) * 1000 - (float) $timestamp < $minSubmitSeconds * 1000) {
            return 'Spam detected (type 3).';
        }
        // Spam keyword detected.
        if (is_string($message)) {
            foreach ($spamKeywords as $keyword) {
                if ($keyword !== '' && preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $message)) {
                    return 'Spam detected (type 4).';
                }
            }
        }

        return null;
    }
}
