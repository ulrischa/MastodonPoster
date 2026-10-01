<?php
require_once __DIR__ . '/MastodonPoster.php';

// Set environment variables instead of committing credentials.
$access_token = getenv('MASTODON_ACCESS_TOKEN');
$instance_url = getenv('MASTODON_INSTANCE_URL') ?: 'https://mastodon.social';

try {
    $mastodon = new ulrischa\MastodonPoster($access_token, $instance_url);
    // Running this example publishes one text-only status.
    $status_response = $mastodon->postStatus('Hello Mastodon!', null, null, [
        'visibility' => 'unlisted',
        'language' => 'en',
    ]);
    echo 'Created status ' . $status_response['id'] . PHP_EOL;

    // To attach an image, replace the call above with:
    // $status_response = $mastodon->postStatus('A photo', ['image.jpg'], ['Description of the photo']);
} catch (\Throwable $error) {
    error_log($error->getMessage());
    exit(1);
}
