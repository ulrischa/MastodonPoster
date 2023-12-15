# MastodonPoster
Simple PHP class to post status with images on mastodon.

## How to use it?
### 1. Create App in Mastodon
Create an app and get an access token from Mastodon: https://mastodon.social/settings/applications

You just need an applicationname and read + write permissions

Copy the Accesstoken to use it in your code.

### 2. Use code
See example.php

```php
require_once('MastodonPoster.php');

// Usage example
$accessToken = 'XXX'; // Replace with your access token created before (see 1.)
$mastodon = new ulrischa\MastodonClient($accessToken);

// Post a status with multiple media
$filePaths = ['image.jpg'];
$descriptions = ['First image description'];
$statusResponse = $mastodon->postStatusWithMedia('Your status text', $filePaths, $descriptions);
print_r($statusResponse);

// Post a text-only status
$textStatusResponse = $mastodon->postStatus('Your text-only status');
print_r($textStatusResponse);
```


