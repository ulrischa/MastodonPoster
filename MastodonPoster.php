<?php
namespace ulrischa;

class MastodonPoster
{
    private $accessToken;
    private $apiBaseUrl;
    private $timeout;

    public function __construct($accessToken, $apiBaseUrl = 'https://mastodon.social', $timeout = 30)
    {
        if (!is_string($accessToken) || !preg_match('/^[\x21-\x7e]+$/D', $accessToken)) {
            throw new \InvalidArgumentException('A non-empty access token without whitespace is required.');
        }
        $parts = is_string($apiBaseUrl) ? parse_url($apiBaseUrl) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || !filter_var($apiBaseUrl, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('The instance must be an HTTPS origin, e.g. https://mastodon.social.');
        }
        if (!is_int($timeout) || $timeout < 1) {
            throw new \InvalidArgumentException('The request timeout must be a positive integer.');
        }
        $this->accessToken = $accessToken;
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
        $this->timeout = $timeout;
    }

    /** Existing three-argument calls remain supported. */
    public function postStatus($statusText, $filePaths = null, $descriptions = null, array $options = [])
    {
        if (!is_string($statusText)) {
            throw new \InvalidArgumentException('Status text must be a string.');
        }
        $filePaths = $filePaths ?? [];
        $descriptions = $descriptions ?? [];
        if (!is_array($filePaths) || !is_array($descriptions)) {
            throw new \InvalidArgumentException('File paths and descriptions must be arrays or null.');
        }
        if (trim($statusText) === '' && !$filePaths) {
            throw new \InvalidArgumentException('A status needs text or at least one attachment.');
        }
        $headers = [];
        if (array_key_exists('idempotency_key', $options)) {
            $key = $options['idempotency_key'];
            if (!is_string($key) || !preg_match('/^[\x21-\x7e]{1,255}$/D', $key)) {
                throw new \InvalidArgumentException('The idempotency key must contain 1–255 printable ASCII characters without spaces.');
            }
            $headers[] = 'Idempotency-Key: ' . $key;
            unset($options['idempotency_key']);
        }
        $this->validateOptions($options);
        $payload = array_merge(['status' => $statusText], $options);
        // Validate JSON and every file before the first upload has side effects.
        json_encode($payload, JSON_THROW_ON_ERROR);
        $files = [];
        foreach ($filePaths as $index => $filePath) {
            $description = $descriptions[$index] ?? '';
            if (!is_string($description) || preg_match('//u', $description) !== 1) {
                throw new \InvalidArgumentException('Media descriptions must be valid UTF-8 strings.');
            }
            if (!is_string($filePath) || strpos($filePath, "\0") !== false
                || strpos($filePath, '://') !== false || !is_file($filePath) || !is_readable($filePath)) {
                throw new \InvalidArgumentException('Each attachment must be a readable local file.');
            }
            $files[] = [$filePath, $description];
        }
        if ($files) {
            $payload['media_ids'] = [];
            foreach ($files as [$filePath, $description]) {
                $payload['media_ids'][] = $this->uploadMedia($filePath, $description);
            }
        }
        $response = $this->sendHttp('/api/v1/statuses', $payload, $headers);
        $this->requireId($response['data']);
        return $response['data'];
    }

    private function validateOptions(array $options)
    {
        $allowed = ['visibility', 'sensitive', 'spoiler_text', 'language', 'in_reply_to_id', 'scheduled_at'];
        foreach ($options as $name => $value) {
            if (!in_array($name, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported status option: ' . $name);
            }
            if ($name === 'sensitive' ? !is_bool($value) : !is_string($value)) {
                throw new \InvalidArgumentException('Invalid type for status option: ' . $name);
            }
        }
        if (isset($options['visibility']) && !in_array($options['visibility'], ['public', 'unlisted', 'private', 'direct'], true)) {
            throw new \InvalidArgumentException('Invalid status visibility.');
        }
        if (isset($options['language']) && !preg_match('/^[a-z]{2}$/D', $options['language'])) {
            throw new \InvalidArgumentException('Language must be a two-letter lowercase code.');
        }
        if (isset($options['in_reply_to_id']) && !preg_match('/^[0-9]+$/D', $options['in_reply_to_id'])) {
            throw new \InvalidArgumentException('The reply ID must be a numeric string.');
        }
        if (isset($options['scheduled_at'])) {
            $date = $options['scheduled_at'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $date)
                || strtotime($date) === false || strtotime($date) < time() + 300) {
                throw new \InvalidArgumentException('Schedule an ISO 8601 timestamp with timezone at least five minutes ahead.');
            }
        }
    }

    private function requireId(array $data)
    {
        if (!isset($data['id']) || !is_string($data['id']) || !preg_match('/^[0-9]+$/D', $data['id'])) {
            throw new \RuntimeException('Mastodon returned a missing or invalid ID.');
        }
        return $data['id'];
    }

    private function uploadMedia($filePath, $description)
    {
        if (!class_exists('CURLFile') || !class_exists('finfo')) {
            throw new \RuntimeException('PHP extensions curl and fileinfo are required for media uploads.');
        }
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($filePath);
        if ($mimeType === false) {
            throw new \RuntimeException('Could not detect the attachment MIME type.');
        }
        $response = $this->sendHttp('/api/v2/media', [
            'file' => new \CURLFile($filePath, $mimeType, basename($filePath)),
            'description' => $description,
        ], [], true);
        $id = $this->requireId($response['data']);
        // Bound polling; never create the status while an attachment is processing.
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if ($response['status'] === 200 && !empty($response['data']['url'])) {
                return $id;
            }
            $this->waitForMedia();
            $response = $this->sendHttp('/api/v1/media/' . $id);
        }
        if ($response['status'] === 200 && !empty($response['data']['url'])) {
            return $id;
        }
        throw new \RuntimeException('Media processing did not finish after 30 checks. No status was posted.');
    }

    protected function waitForMedia()
    {
        sleep(1);
    }

    /** Protected to allow offline tests without publishing real statuses. */
    protected function sendHttp($path, $data = null, array $customHeaders = [], $multipart = false)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP curl extension is required.');
        }
        $headers = array_merge(['Authorization: Bearer ' . $this->accessToken, 'Accept: application/json'], $customHeaders);
        if ($data !== null && !$multipart) {
            $headers[] = 'Content-Type: application/json';
            $data = json_encode($data, JSON_THROW_ON_ERROR);
        }
        $handle = curl_init($this->apiBaseUrl . $path);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize cURL.');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($data !== null) {
                curl_setopt($handle, CURLOPT_POST, true);
                // cURL supplies the multipart boundary for file uploads.
                curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
            }
            $body = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($body === false) {
                throw new \RuntimeException('Mastodon transport failed (cURL ' . curl_errno($handle) . ').');
            }
        } finally {
            curl_close($handle);
        }
        return $this->decodeResponse($body, $status);
    }

    protected function decodeResponse($body, $status)
    {
        $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($data) && isset($data['error']) && is_string($data['error']) ? $data['error'] : 'Request failed';
            $detail = str_replace($this->accessToken, '[redacted]', $detail);
            throw new \RuntimeException('Mastodon HTTP ' . $status . ': ' . $detail, $status);
        }
        // Mastodon can return an empty 206 response while media is processing.
        if ($status === 206 && trim($body) === '') {
            return ['status' => $status, 'data' => []];
        }
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || substr(ltrim($body), 0, 1) !== '{') {
            throw new \RuntimeException('Mastodon returned an invalid JSON object.');
        }
        if (isset($data['error'])) {
            throw new \RuntimeException('Mastodon returned an unexpected API error.');
        }
        return ['status' => $status, 'data' => $data];
    }
}
