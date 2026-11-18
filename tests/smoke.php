<?php

declare(strict_types=1);

use App\Config;
use App\RateLimiter;
use App\Request;
use App\Validation;

require dirname(__DIR__).'/src/ConfigurationException.php';
require dirname(__DIR__).'/src/Config.php';
require dirname(__DIR__).'/src/RateLimiter.php';
require dirname(__DIR__).'/src/Request.php';
require dirname(__DIR__).'/src/Validation.php';

/**
 * Assert that a smoke test condition is satisfied.
 */
function check(bool $condition, string $description): void
{
    if (! $condition) {
        throw new RuntimeException($description);
    }

    echo "PASS: {$description}\n";
}

$config = new Config([
    'FREESCOUT_API_URL' => 'https://example.com/api/conversations',
    'FREESCOUT_API_KEY' => 'test',
    'COMPANY_MAILBOX_ID_ACME_CO' => '2',
    'COMPANY_ALLOWED_ORIGIN_ACME_CO' => 'https://example.com, https://example.net',
]);

check(Config::normalizeSlug('Acme-Co') === 'acmeco', 'Slug normalization');
check($config->mailbox('acmeco') === 2, 'Mailbox lookup');
check(count($config->origins('acmeco')) === 2, 'Origin parsing');
check($config->rateLimitSeconds === 5 && $config->minSubmitSeconds === 5, 'Default timing configuration');
$baseEnv = ['FREESCOUT_API_URL' => 'https://example.com', 'FREESCOUT_API_KEY' => 'test'];
check($config->minMessageLength === 5, 'Default minimum message length');
check((new Config($baseEnv + ['MIN_MESSAGE_LENGTH' => '20']))->minMessageLength === 20, 'Custom minimum message length');
check((new Config($baseEnv + ['MIN_MESSAGE_LENGTH' => '5000']))->minMessageLength === 5000, 'Minimum message length at cap');
foreach (['0', '-1', '5001', 'abc', '1.5', ''] as $value) {
    $rejected = false;
    try {
        new Config($baseEnv + ['MIN_MESSAGE_LENGTH' => $value]);
    } catch (App\ConfigurationException $exception) {
        $rejected = str_contains($exception->getMessage(), 'MIN_MESSAGE_LENGTH');
    }
    check($rejected, "Reject invalid MIN_MESSAGE_LENGTH: '{$value}'");
}
foreach (['FREESCOUT_API_URL', 'FREESCOUT_API_KEY'] as $name) {
    $invalidEnv = $baseEnv;
    $invalidEnv[$name] = '';
    $message = '';
    try {
        new Config($invalidEnv);
    } catch (App\ConfigurationException $exception) {
        $message = $exception->getMessage();
    }
    check(str_contains($message, $name) && ! str_contains($message, $baseEnv[$name]), "Identify empty {$name} without exposing its value");
}
$customConfig = new Config($baseEnv + ['RATE_LIMIT_SECONDS' => '12', 'MIN_SUBMIT_SECONDS' => '2']);
check($customConfig->rateLimitSeconds === 12 && $customConfig->minSubmitSeconds === 2, 'Custom timing configuration');
check((new Config($baseEnv + ['MIN_SUBMIT_SECONDS' => '0']))->minSubmitSeconds === 0, 'Allow zero submission delay');
foreach (['RATE_LIMIT_SECONDS' => ['0', '-1', 'abc', '1.5', ''], 'MIN_SUBMIT_SECONDS' => ['-1', 'abc', '1.5', '']] as $name => $values) {
    foreach ($values as $value) {
        $rejected = false;
        try {
            new Config($baseEnv + [$name => $value]);
        } catch (RuntimeException $exception) {
            $rejected = str_contains($exception->getMessage(), $name);
        }
        check($rejected, "Reject invalid {$name}: '{$value}'");
    }
}
check(Validation::isValidEmail('joe@example.com'), 'Valid email');
check(! Validation::isValidEmail('joe@example'), 'Invalid email');
check(Validation::spamError(['website' => 'bot']) === 'Spam detected (type 1).', 'Honeypot');
check(Validation::spamError(['formTs' => 'invalid']) === 'Spam detected (type 2).', 'Malformed timestamp');
check(Validation::spamError(['formTs' => microtime(true) * 1000]) === 'Spam detected (type 3).', 'Fast submission');
check(Validation::spamError(['formTs' => 1, 'message' => 'crypto']) === 'Spam detected (type 4).', 'Keyword blocklist');
check(Validation::spamError(['formTs' => 1, 'message' => 'Hello there']) === null, 'Clean submission');
check($config->spamKeywords === ['viagra', 'cialis', 'loan approval', 'crypto'], 'Default spam keywords');
$keywordConfig = new Config($baseEnv + ['SPAM_KEYWORDS' => ' special offer, a.b, , ']);
check($keywordConfig->spamKeywords === ['special offer', 'a.b'], 'Trim spam keywords and ignore empty entries');
check(Validation::spamError(['formTs' => 1, 'message' => 'SPECIAL OFFER today'], 5, $keywordConfig->spamKeywords) === 'Spam detected (type 4).', 'Custom phrase matched case-insensitively');
check(Validation::spamError(['formTs' => 1, 'message' => 'a.b'], 5, $keywordConfig->spamKeywords) === 'Spam detected (type 4).', 'Literal keyword punctuation');
check(Validation::spamError(['formTs' => 1, 'message' => 'axb crypto'], 5, $keywordConfig->spamKeywords) === null, 'Custom keywords replace defaults and escape regex syntax');
check(Validation::spamError(['formTs' => 1, 'message' => 'cryptography']) === null, 'Keyword word boundaries');
$noKeywords = new Config($baseEnv + ['SPAM_KEYWORDS' => '']);
check(Validation::spamError(['formTs' => 1, 'message' => 'crypto'], 5, $noKeywords->spamKeywords) === null, 'Empty keyword list disables blocking');
$recentSubmission = ['formTs' => (microtime(true) - 3) * 1000];
check(Validation::spamError($recentSubmission, $customConfig->minSubmitSeconds) === null, 'Custom shorter submission delay');
check(Validation::spamError($recentSubmission, 10) === 'Spam detected (type 3).', 'Custom longer submission delay');
check(Validation::spamError(['formTs' => microtime(true) * 1000], 0) === null, 'Immediate submission with zero delay');
$request = new Request(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.0.2.1', 'CONTENT_TYPE' => 'application/json'], [], '{"subject":"Hello"}');
check($request->ip([]) === '127.0.0.1', 'Ignore untrusted forwarding');
check($request->ip(['127.0.0.1']) === '192.0.2.1', 'Trust configured proxy');
check($request->data() === ['subject' => 'Hello'], 'JSON parsing');
$file = tempnam(sys_get_temp_dir(), 'freescout-');

try {
    $limiter = new RateLimiter($file, $customConfig->rateLimitSeconds);
    $first = $limiter->hit('acme', '192.0.2.1');
    check($first['allowed'], 'First request allowed');
    check($first['reset'] === 12, 'Configured rate limit reset');
    check(! $limiter->hit('acme', '192.0.2.1')['allowed'], 'Repeated request blocked');
    check($limiter->hit('other', '192.0.2.1')['allowed'], 'Independent company quota');
    $entries = json_decode(file_get_contents($file), true);
    $entries[hash('sha256', 'acme:192.0.2.1')] = microtime(true) - 1;
    file_put_contents($file, json_encode($entries));
    check($limiter->hit('acme', '192.0.2.1')['allowed'], 'Request allowed after interval expires');
} finally {
    unlink($file);
}

if (extension_loaded('mbstring')) {
    check(Validation::sanitize("  hé\0llo  ", 3) === 'hél', 'Unicode sanitization');
} else {
    echo "SKIP: Unicode sanitization (mbstring unavailable).\n";
}
