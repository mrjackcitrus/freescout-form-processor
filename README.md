# FreeScout Form Processor

A small PHP service that accepts POST requests from website contact forms and creates FreeScout conversations. It supports multiple company mailboxes, per-company CORS, basic spam checks and rate limiting.

## Requirements

- PHP 8.2 or newer with cURL and mbstring enabled.
- Composer 2.
- A writable `storage/` directory on the server to assist with rate limits.
- A FreeScout instance with the official [API & Webhooks Module](https://freescout.net/module/api-webhooks/) installed.

## Installation

Install dependencies:

```sh
composer install --no-dev --optimize-autoloader
```

Create a new `.env` file.

```sh
cp .env.example .env
```

## Environment Variables

Edit `.env` with your FreeScout credentials and company mappings. See [`.env.example`](.env.example) for a complete example.

| Variable | Default | Description |
| --- | --- | --- |
| `FREESCOUT_API_URL` | Required | FreeScout conversations API endpoint, e.g. `https://freescout.example.com/api/conversations`. |
| `FREESCOUT_API_KEY` | Required | API key used to authenticate with FreeScout. |
| `TRUSTED_PROXIES` | Empty | Comma-separated exact IP addresses of trusted reverse proxies. |
| `RATE_LIMIT_SECONDS` | `5` | Interval between requests for each company and client IP. Must be a positive integer in seconds. |
| `MIN_SUBMIT_SECONDS` | `5` | Minimum time between form load and submission. Must be a non-negative integer in seconds; `0` allows immediate submission while still requiring a valid timestamp. |
| `MIN_MESSAGE_LENGTH` | `5` | Minimum message length after trimming and removing null bytes, in Unicode characters. Must be an integer from `1` to `5000` (the message length cap). |
| `SPAM_KEYWORDS` | `viagra,cialis,loan approval,crypto` | Comma-separated literal words or phrases blocked in the message, matched case-insensitively at word boundaries. Surround the value with double quotes if it contains spaces. Set to an empty value to disable keyword blocking. |
| `COMPANY_MAILBOX_ID_<SLUG>` | None | Positive integer FreeScout mailbox ID for the company. Add one entry per company. |
| `COMPANY_ALLOWED_ORIGIN_<SLUG>` | Empty | Comma-separated origins permitted by CORS for the company, e.g. `https://example.com,http://localhost:8000`. |

Use the same company suffix for each mailbox and its allowed origins:

```dotenv
COMPANY_MAILBOX_ID_ACME=1
COMPANY_ALLOWED_ORIGIN_ACME=https://example.com
```

This configures the `/acme` endpoint. Add more pairs for additional companies. Timing defaults apply when the variables are omitted; invalid timing values prevent application startup.

## Development

```sh
composer start
```

Or use `php -S localhost:8000 -t public public/index.php`.

## Production

Use PHP-FPM behind Nginx (or an equivalent PHP-capable web server), with `public/` as the document root. Route requests through `public/index.php`. 

If PHP receives requests through a trusted HTTP reverse proxy, list its exact IP addresses in `TRUSTED_PROXIES`. Ensure that proxy correctly sets or appends `X-Forwarded-For`. If Nginx already supplies the real client address via FastCGI, no trusted proxy entry is needed.

## Slug Rules

The request path uses `/:slug`. The slug is normalized to lowercase and only alphanumeric characters remain.

Example: `/acme-mugs_and_chinaware` becomes acmemugsandchinaware.

A slug is valid only if a matching `COMPANY_MAILBOX_ID_*` key exists after normalization.

## Basic Spam Prevention

Submissions pass through the following checks in order. The first failed check returns HTTP `403` with a `Spam detected (type N).` message.

| Type | Check | Rejected when |
| --- | --- | --- |
| `1` | Honeypot | The hidden `website` field contains a non-whitespace value or a non-scalar value, such as an array. |
| `2` | Timestamp | `formTs` is missing, non-numeric, zero, or negative. Supply a Unix timestamp in milliseconds, generated when the form loads. |
| `3` | Time to submit | Less than `MIN_SUBMIT_SECONDS` has elapsed since `formTs`, or the timestamp is in the future. The default delay is five seconds; `0` allows immediate submission. |
| `4` | Keyword blocklist | The message matches a word or phrase in `SPAM_KEYWORDS`. Matching ignores case, uses word boundaries, and treats keywords as literal text. |

Set `SPAM_KEYWORDS=` to disable keyword blocking. A valid timestamp is still required when `MIN_SUBMIT_SECONDS=0`. See [Environment Variables](#environment-variables) for configuration and defaults.

Field validation runs after these checks: an invalid email, empty subject, or message shorter than `MIN_MESSAGE_LENGTH` characters (five by default) returns HTTP `422` with a specific validation message.

## Rate Limits

Each company and client IP can make one request per `RATE_LIMIT_SECONDS` interval (five seconds by default). Requests over the limit receive HTTP `429` with a `Retry-After` header. Failed validation and spam checks also consume the request allowance.

Limits are stored in `storage/rate-limits.json`, with file locking to coordinate concurrent PHP requests. File storage keeps setup simple and avoids requiring a database or Redis for a small, low-traffic service.

## Endpoints

- `GET /`: plain-text `OK` health check.
- `POST /:slug`: accepts JSON or URL-encoded forms.
- `OPTIONS /:slug`: CORS preflight.

## Example Form

```html
<form action="http://localhost:8000/companyslug" method="post">
  <input name="firstName" placeholder="First name">
  <input name="lastName" placeholder="Last name">
  <input type="email" name="email" required>
  <input name="subject" required>
  <textarea name="message" required></textarea>
  <div style="position:absolute;left:-5000px;width:1px;height:1px;overflow:hidden;">
    <input name="website" tabindex="-1" autocomplete="off">
  </div>
  <input type="hidden" name="formTs" id="formTs">
  <button type="submit">Send</button>
</form>
<script>
  document.getElementById('formTs').value = Date.now();
</script>
```

## Tests

```sh
composer test
```

This runs the smoke tests and PHP syntax checks. Run them separately with `composer test:smoke` or `composer lint`.

The simple smoke tests cover configuration, validation, request parsing, trusted proxies and rate limiting without contacting FreeScout.

There are no live FreeScout integration tests, but you can manually test using the commands below:

### Manual Curl Tests

Happy path:

```bash
FORM_TS=$(php -r 'echo (int) floor(microtime(true) * 1000);'); sleep 5; \
curl -i http://localhost:8000/companyslug \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data "firstName=Joe&lastName=Bloggs&email=j.bloggs@mailinator.com&subject=Test&message=Hello&website=&formTs=$FORM_TS"
```

Honeypot trigger:

```bash
curl -s -X POST http://localhost:8000/companyslug \
  -H "Content-Type: application/json" \
  -d '{"email":"joe@test.com","subject":"test","message":"hi","website":"bot"}'
```

Too-fast trigger:

```bash
FORM_TS=$(php -r 'echo (int) floor(microtime(true) * 1000);'); \
curl -i http://localhost:8000/companyslug \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data "firstName=Joe&lastName=Bloggs&email=j.bloggs@mailinator.com&subject=Test&message=Hello&website=&formTs=$FORM_TS"
```
