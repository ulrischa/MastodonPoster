<?php

namespace ulrischa;

class MastodonClient {
    private $accessToken;
    private $apiBaseUrl = 'https://mastodon.social';

    public function __construct($accessToken) {
        $this->accessToken = $accessToken;
    }

    private function sendHttp($url, $custom_headers = [], $post_data = null) {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        $headers = array_merge($headers, $custom_headers);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($post_data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            die('Error: ' . $error);
        }

        return json_decode($response, true);
    }

    public function uploadMedia($filePath, $description) {
        $url = $this->apiBaseUrl . '/api/v1/media';

        $file = new \CURLFile($filePath);
        $postData = ['file' => $file, 'description' => $description];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: multipart/form-data'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            die('Error: ' . $error);
        }

        return json_decode($response, true);
    }

    public function postStatusWithMedia($statusText, $filePaths, $descriptions) {
        $mediaIds = [];
        foreach ($filePaths as $index => $filePath) {
            $description = $descriptions[$index] ?? '';
            $uploadResponse = $this->uploadMedia($filePath, $description);
            $mediaIds[] = $uploadResponse['id'] ?? null;
        }

        return $this->sendHttp($this->apiBaseUrl . '/api/v1/statuses', [], ['status' => $statusText, 'media_ids' => $mediaIds]);
    }

    public function postStatus($statusText) {
        return $this->sendHttp($this->apiBaseUrl . '/api/v1/statuses', [], ['status' => $statusText]);
    }
}









?>
