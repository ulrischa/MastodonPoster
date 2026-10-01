# MastodonPoster

**A small, dependency-free PHP class for posting text and local media to Mastodon.**

## Requirements

**PHP 7.4 or newer with `curl`, `json` and `fileinfo`. Use a currently maintained PHP release for production. No Composer installation is needed. Media uploads require Mastodon 3.1.3 or newer.**

## Setup

1. **On your Mastodon instance, open Settings → Development → New application (`https://YOUR-INSTANCE/settings/applications`).**
2. **Grant `write:statuses` and, for attachments, `write:media`. Copy the user access token. Broad read permissions are unnecessary.**
3. **Download `MastodonPoster.php` and include it in your application. Keep the token in an environment variable or a configuration file outside the public web directory and version control.**

```php
require_once __DIR__ . '/MastodonPoster.php';

$access_token = getenv('MASTODON_ACCESS_TOKEN');
$mastodon = new ulrischa\MastodonPoster($access_token, 'https://mastodon.social');

try {
    $status_response = $mastodon->postStatus('Hello Mastodon!');
    echo $status_response['id'];
} catch (\Throwable $error) {
    error_log($error->getMessage());
}
```

**The instance URL defaults to `https://mastodon.social` and must be an HTTPS origin without a path, credentials, query or fragment. Use the instance that issued your token. The optional third constructor argument sets the timeout per HTTP request in seconds (default: 30; connection timeout: at most 10). Only configure trusted instance URLs; do not take this value directly from public form input.**

## Post media

```php
$status_response = $mastodon->postStatus(
    'A view from the mountains',
    [__DIR__ . '/mountains.jpg', __DIR__ . '/valley.jpg'],
    ['Snow-covered mountain peaks', 'A green valley below the peaks']
);
```

**Descriptions use the same array keys as file paths. They are optional, but provide alternative text for accessibility. Missing descriptions become empty strings. An empty status text is allowed when attaching media. Only readable local files are accepted; use trusted application-controlled paths. Instance-specific file size, media count, format and text-length limits are enforced by Mastodon.**

**Uploads use `/api/v2/media` with detected MIME types. Pending uploads are checked through `/api/v1/media/:id` up to 30 times, with a one-second pause between checks. HTTP request time is additional. The status is created only after every attachment is ready. A failure can leave unattached uploads on the instance; the class does not automatically delete them.**

## Posting options

**The optional fourth argument adds posting controls while preserving existing one- and three-argument calls:**

```php
$status_response = $mastodon->postStatus('Your status text', null, null, [
    'visibility' => 'unlisted',
    'sensitive' => false,
    'spoiler_text' => 'Content warning',
    'language' => 'en',
    'idempotency_key' => 'my-job-2026-10-01-001',
]);
```

| Option | Value |
| --- | --- |
| **`visibility`** | **`public`, `unlisted`, `private` (followers only), or `direct` (mentioned accounts)** |
| **`sensitive`** | **Boolean; mark attached media as sensitive** |
| **`spoiler_text`** | **Content warning string** |
| **`language`** | **Two-letter lowercase language code, e.g. `de`** |
| **`in_reply_to_id`** | **Numeric string ID of the status to reply to** |
| **`scheduled_at`** | **ISO 8601 timestamp with timezone, at least five minutes ahead; allow extra time for uploads** |
| **`idempotency_key`** | **Stable key for retrying the same logical post; 1–255 printable ASCII characters without spaces** |

**Omitted options retain the server/account defaults. Unknown options are rejected to catch typos. Immediate posts return Mastodon's Status object as an associative array; scheduled posts return a ScheduledStatus object. IDs remain strings.**

**Mastodon retains idempotency keys for up to one hour. Reuse a key only for retries of the same post and use a new key for each new post. The key applies to status creation, not media uploads. The client does not retry automatically: after a network timeout, the server may already have created the post. Check before retrying without a key.**

## Errors

**Invalid input throws `InvalidArgumentException`; invalid UTF-8 in the JSON payload throws `JsonException`; transport, HTTP and malformed-response errors throw `RuntimeException`. HTTP errors expose the response status through `$error->getCode()` (including 401, 422 and 429). TLS certificate verification stays enabled, redirects are not followed, and errors never terminate the host application with `die()`. Do not display raw exception messages to public visitors.**

## Example and tests

**`example.php` reads `MASTODON_ACCESS_TOKEN` and optionally `MASTODON_INSTANCE_URL`. Running it publishes one unlisted text status.**

```sh
php example.php
```

**The regression tests use simulated API responses, require no token, and publish nothing:**

```sh
php tests/run.php
```

**GitHub Actions runs syntax checks and the tests on PHP 7.4, 8.3, 8.4 and 8.5. The suite covers validation, optional descriptions, multiple attachments, asynchronous processing, upload failures, posting options, idempotency and malformed/HTTP error responses. It does not replace an integration test against your Mastodon instance.**

## Changes

- **Fixed the example's nonexistent class and method names.**
- **Fixed attachments being silently skipped when descriptions were omitted.**
- **Added custom instances, timeouts, exceptions and response validation.**
- **Added asynchronous media handling, media-only posts and posting options.**
- **Added offline regression tests and CI.**

**API references: [statuses](https://docs.joinmastodon.org/methods/statuses/) · [media](https://docs.joinmastodon.org/methods/media/).**
