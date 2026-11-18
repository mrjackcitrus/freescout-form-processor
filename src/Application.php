<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use Throwable;

/**
 * Route HTTP requests and process incoming contact form submissions.
 */
final class Application
{
    /**
     * Create a new application instance.
     */
    public function __construct(
        private readonly Config $config,
        private readonly RateLimiter $limiter,
        private readonly FreeScout $freeScout,
    ) {}

    /**
     * Dispatch an incoming request and send the appropriate response.
     */
    public function handle(Request $request): void
    {
        $this->securityHeaders();

        if ($request->path() === '/' && in_array($request->method(), ['GET', 'HEAD'], true)) {
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'OK';

            return;
        }

        if (! preg_match('~^/([^/]+)/?$~D', $request->path(), $matches)
            || ! in_array($request->method(), ['POST', 'OPTIONS'], true)) {
            $this->json(404, ['message' => 'Not found.']);

            return;
        }

        $rawSlug = rawurldecode($matches[1]);
        $slug = Config::normalizeSlug($rawSlug);
        $origin = $request->header('Origin');
        $this->cors($origin, $slug, $request);

        if ($request->method() === 'OPTIONS') {
            http_response_code(204);

            return;
        }

        try {
            $data = $request->data();
            $ip = $request->ip($this->config->trustedProxies);
            $limit = $this->limiter->hit($slug, $ip);

            header('RateLimit-Policy: 1;w='.$this->config->rateLimitSeconds);
            header('RateLimit-Limit: 1');
            header('RateLimit-Remaining: 0');
            header('RateLimit-Reset: '.$limit['reset']);

            if (! $limit['allowed']) {
                header('Retry-After: '.$limit['reset']);
                http_response_code(429);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Too many requests, please try again later.';

                return;
            }

            $this->contact($rawSlug, $slug, $ip, $data);
        } catch (InvalidArgumentException $exception) {
            $this->json($exception->getCode(), ['message' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $timeout = $exception->getCode() === 28;

            if (! $timeout) {
                error_log('Contact error: '.$exception->getMessage());
            }

            $this->json(500, ['message' => $timeout
                ? 'Request timed out. Please try again.'
                : 'Unexpected error processing your request.']);
        }
    }

    /**
     * Validate a submission and create the corresponding FreeScout conversation.
     */
    private function contact(string $rawSlug, string $slug, string $ip, array $data): void
    {
        $mailboxId = $this->config->mailbox($slug);

        if ($mailboxId === null) {
            $this->json(404, ['message' => "Unknown company slug '{$rawSlug}'."]);

            return;
        }

        if (($error = Validation::spamError($data, $this->config->minSubmitSeconds, $this->config->spamKeywords)) !== null) {
            $this->json(403, ['message' => $error]);

            return;
        }

        $email = mb_strtolower(Validation::sanitize($data['email'] ?? '', 254), 'UTF-8');
        $firstName = Validation::sanitize($data['firstName'] ?? '', 100);
        $lastName = Validation::sanitize($data['lastName'] ?? '', 100);
        $subject = Validation::sanitize($data['subject'] ?? '', 200);
        $message = Validation::sanitize($data['message'] ?? '', 5000);

        if (! Validation::isValidEmail($email)) {
            $this->json(422, ['message' => 'Invalid email.']);

            return;
        }

        if ($subject === '') {
            $this->json(422, ['message' => 'Subject is required.']);

            return;
        }

        if (mb_strlen($message, 'UTF-8') < $this->config->minMessageLength) {
            $this->json(422, ['message' => 'Message is too short.']);

            return;
        }

        $customer = ['email' => $email];

        if ($firstName !== '') {
            $customer['firstName'] = $firstName;
        }

        if ($lastName !== '') {
            $customer['lastName'] = $lastName;
        }

        $result = $this->freeScout->createConversation(
            $mailboxId,
            'Contact Form: '.$subject,
            $customer,
            $message."\n\n\nIP ".$ip,
        );

        $this->json(200, [
            'message' => 'Message sent.',
            'freescout' => ['id' => $result['id'] ?? null, 'status' => 'created'],
            'company' => $slug,
        ]);
    }

    /**
     * Apply company-specific CORS headers for an explicitly permitted origin.
     */
    private function cors(string $origin, string $slug, Request $request): void
    {
        header('Vary: Origin');

        if ($origin === '' || ! in_array($origin, $this->config->origins($slug), true)) {
            return;
        }

        header('Access-Control-Allow-Origin: '.$origin);

        if ($request->method() === 'OPTIONS') {
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Vary: Access-Control-Request-Headers', false);
            $headers = $request->header('Access-Control-Request-Headers');

            if ($headers !== '') {
                header('Access-Control-Allow-Headers: '.$headers);
            }
        }
    }

    /**
     * Send security headers equivalent to the original Helmet defaults.
     */
    private function securityHeaders(): void
    {
        header_remove('X-Powered-By');
        header("Content-Security-Policy: default-src 'self';base-uri 'self';font-src 'self' https: data:;form-action 'self';frame-ancestors 'self';img-src 'self' data:;object-src 'none';script-src 'self';script-src-attr 'none';style-src 'self' https: 'unsafe-inline';upgrade-insecure-requests");
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Origin-Agent-Cluster: ?1');
        header('Referrer-Policy: no-referrer');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('X-Content-Type-Options: nosniff');
        header('X-DNS-Prefetch-Control: off');
        header('X-Download-Options: noopen');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('X-XSS-Protection: 0');
    }

    /**
     * Send a JSON response with the given HTTP status code.
     */
    private function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
