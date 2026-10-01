<?php
require_once __DIR__ . '/../MastodonPoster.php';

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expect_exception($callback, $class, $code = null)
{
    try {
        $callback();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected exception: ' . get_class($error) . ': ' . $error->getMessage());
        if ($code !== null) {
            check($error->getCode() === $code, 'Unexpected exception code');
        }
        return $error;
    }
    throw new RuntimeException('Expected ' . $class);
}

class FakePoster extends ulrischa\MastodonPoster
{
    public $requests = [];
    public $responses = [];
    public $waits = 0;

    protected function sendHttp($path, $data = null, array $customHeaders = [], $multipart = false)
    {
        $this->requests[] = [$path, $data, $customHeaders, $multipart];
        if (!$this->responses) {
            throw new LogicException('Unexpected request: ' . $path);
        }
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        return $response;
    }

    protected function waitForMedia()
    {
        $this->waits++;
    }

    public function decode($body, $status)
    {
        return $this->decodeResponse($body, $status);
    }
}

function response($data, $status = 200)
{
    return ['data' => $data, 'status' => $status];
}

$tests = [];
$tests['constructor validation'] = function () {
    new FakePoster('test-token', 'https://example.org:8443/');
    foreach (['', "token\r\nX-Header: value", 'with space'] as $token) {
        expect_exception(function () use ($token) { new FakePoster($token); }, InvalidArgumentException::class);
    }
    foreach (['http://example.org', 'https://example.org/path', 'https://user@example.org', 'https://example.org?x=1', 'https://example.org/#x'] as $url) {
        expect_exception(function () use ($url) { new FakePoster('test-token', $url); }, InvalidArgumentException::class);
    }
    expect_exception(function () { new FakePoster('test-token', 'https://example.org', 0); }, InvalidArgumentException::class);
};
$tests['backwards-compatible text status'] = function () {
    $poster = new FakePoster('test-token');
    $poster->responses = [response(['id' => '123'])];
    check($poster->postStatus('Grüße!')['id'] === '123', 'Return API status');
    check($poster->requests === [['/api/v1/statuses', ['status' => 'Grüße!'], [], false]], 'Text-only payload');
};
$tests['status options and idempotency header'] = function () {
    $poster = new FakePoster('test-token');
    $poster->responses = [response(['id' => '123'])];
    $options = ['visibility' => 'private', 'sensitive' => true, 'spoiler_text' => 'Warning', 'language' => 'de', 'in_reply_to_id' => '456', 'scheduled_at' => '2099-01-01T12:00:00Z', 'idempotency_key' => 'stable-key'];
    $poster->postStatus('Text', null, null, $options);
    unset($options['idempotency_key']);
    check($poster->requests[0][1] === array_merge(['status' => 'Text'], $options), 'Options forwarded');
    check($poster->requests[0][2] === ['Idempotency-Key: stable-key'], 'Idempotency sent as header');
};
$tests['invalid input fails before uploads'] = function () {
    $poster = new FakePoster('test-token');
    foreach ([['visibility' => 'typo'], ['sensitive' => 'false'], ['media_ids' => ['1']], ['language' => 'German'], ['in_reply_to_id' => '../1'], ['scheduled_at' => 'tomorrow'], ['idempotency_key' => "key\nInjected: yes"]] as $options) {
        expect_exception(function () use ($poster, $options) { $poster->postStatus('Text', [__FILE__], null, $options); }, InvalidArgumentException::class);
    }
    expect_exception(function () use ($poster) { $poster->postStatus('  '); }, InvalidArgumentException::class);
    expect_exception(function () use ($poster) { $poster->postStatus('Text', [__FILE__, '/missing-file']); }, InvalidArgumentException::class);
    expect_exception(function () use ($poster) { $poster->postStatus("\xFF", [__FILE__]); }, JsonException::class);
    check(!$poster->requests, 'Validation must not upload files');
};
$tests['media without descriptions and media-only status'] = function () {
    $poster = new FakePoster('test-token');
    $poster->responses = [response(['id' => '1', 'url' => 'https://example.org/image']), response(['id' => '2'])];
    $poster->postStatus('', [__FILE__]);
    check($poster->requests[0][0] === '/api/v2/media', 'Use current media endpoint');
    check($poster->requests[0][1]['description'] === '', 'Description optional');
    check($poster->requests[0][1]['file'] instanceof CURLFile, 'Multipart file');
    check($poster->requests[0][1]['file']->getMimeType() !== '', 'Detected MIME type');
    check($poster->requests[0][3] === true, 'Multipart mode');
    check($poster->requests[1][1]['media_ids'] === ['1'], 'Attach uploaded media');
};
$tests['multiple files preserve descriptions and order'] = function () {
    $poster = new FakePoster('test-token');
    $poster->responses = [response(['id' => '10', 'url' => 'https://example.org/a']), response(['id' => '11', 'url' => 'https://example.org/b']), response(['id' => '12'])];
    $poster->postStatus('Text', [__FILE__, __FILE__], ['First', 'Second']);
    check($poster->requests[1][1]['description'] === 'Second', 'Preserve description');
    check($poster->requests[2][1]['media_ids'] === ['10', '11'], 'Preserve media order');
};
$tests['async media waits for readiness'] = function () {
    $poster = new FakePoster('test-token');
    $poster->responses = [response(['id' => '1', 'url' => null], 202), response([], 206), response(['id' => '1', 'url' => 'https://example.org/image']), response(['id' => '2'])];
    $poster->postStatus('Text', [__FILE__]);
    check($poster->waits === 2, 'Wait for processing');
    check($poster->requests[1][0] === '/api/v1/media/1' && $poster->requests[1][1] === null, 'Poll via GET');
    check($poster->requests[3][0] === '/api/v1/statuses', 'Post only after readiness');
};
$tests['processing timeout and upload errors do not post'] = function () {
    $poster = new FakePoster('test-token');
    $poster->responses = array_merge([response(['id' => '1'], 202)], array_fill(0, 30, response([], 206)));
    expect_exception(function () use ($poster) { $poster->postStatus('Text', [__FILE__]); }, RuntimeException::class);
    check(count($poster->requests) === 31, 'Polling bounded');
    foreach ($poster->requests as $request) {
        check($request[0] !== '/api/v1/statuses', 'No status after timeout');
    }
    foreach ([response(['url' => null]), new RuntimeException('Upload rejected', 422)] as $failure) {
        $poster = new FakePoster('test-token');
        $poster->responses = [$failure];
        expect_exception(function () use ($poster) { $poster->postStatus('Text', [__FILE__]); }, RuntimeException::class);
        check(count($poster->requests) === 1, 'Stop after failed upload');
    }
};
$tests['HTTP and malformed responses'] = function () {
    $poster = new FakePoster('test-token');
    foreach ([401, 403, 422, 429, 500, 302] as $status) {
        $error = expect_exception(function () use ($poster, $status) { $poster->decode('{"error":"test-token rejected"}', $status); }, RuntimeException::class, $status);
        check(strpos($error->getMessage(), 'test-token') === false, 'Redact token');
    }
    foreach (['', 'null', '[]', '<html>Error</html>', '{broken', '{"error":"Unexpected"}'] as $body) {
        expect_exception(function () use ($poster, $body) { $poster->decode($body, 200); }, RuntimeException::class);
    }
    check($poster->decode('', 206) === response([], 206), 'Accept empty processing response');
    check($poster->decode('{"id":"99999999999999999999"}', 200)['data']['id'] === '99999999999999999999', 'Preserve large ID');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
}
echo count($tests) . ' tests, ' . $failed . ' failures' . PHP_EOL;
exit($failed ? 1 : 0);
