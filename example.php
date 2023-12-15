<?php
require_once('MastodonPoster.php');

// Usage example
$accessToken = 'XXX'; // Replace with your access token
$mastodon = new ulrischa\MastodonClient($accessToken);

// Post a status with multiple media
$filePaths = ['image.jpg'];
$descriptions = ['First image description'];
$statusResponse = $mastodon->postStatusWithMedia('Your status text', $filePaths, $descriptions);
print_r($statusResponse);

// Post a text-only status
$textStatusResponse = $mastodon->postStatus('Your text-only status');
print_r($textStatusResponse);


?>