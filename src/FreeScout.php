<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Create conversations using the FreeScout API.
 */
final class FreeScout
{
    /**
     * Create a new FreeScout client instance.
     */
    public function __construct(private readonly Config $config) {}

    /**
     * Create an active email conversation with an initial customer thread.
     *
     * @throws RuntimeException
     */
    public function createConversation(int $mailboxId, string $subject, array $customer, string $text): array
    {
        $payload = [
            'type' => 'email',
            'mailboxId' => $mailboxId,
            'subject' => $subject,
            'customer' => $customer,
            'threads' => [['text' => $text, 'type' => 'customer']],
            'imported' => false,
            'status' => 'active',
        ];

        $handle = curl_init($this->config->url);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-FreeScout-API-Key: '.$this->config->key,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($response === false) {
            throw new RuntimeException(curl_error($handle), curl_errno($handle));
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("FreeScout returned HTTP {$status}.");
        }

        $data = json_decode($response, true);

        return is_array($data) ? $data : [];
    }
}
