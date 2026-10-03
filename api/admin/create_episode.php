<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

require_method('POST');
require_admin();
require_csrf();

$body = json_body();
$numberValue = $body['episode_number'] ?? null;
if (!is_int($numberValue) || $numberValue < 1) {
    fail('Episode number must be a positive integer.');
}

$limits = ['title' => 200, 'guest_name' => 150, 'category' => 60, 'description' => 1000];
$episode = ['episode_number' => $numberValue];
foreach ($limits as $field => $maxLength) {
    $rawValue = $body[$field] ?? null;
    if (!is_string($rawValue)) {
        fail("{$field} is required and must be text.");
    }
    $value = trim($rawValue);
    if ($value === '' || strlen($value) > $maxLength) {
        fail("{$field} is required and must be no more than {$maxLength} characters.");
    }
    $episode[$field] = $value;
}

$rawYoutubeUrl = $body['youtube_url'] ?? null;
if (!is_string($rawYoutubeUrl)) {
    fail('A valid YouTube URL is required.');
}
$youtubeUrl = trim($rawYoutubeUrl);
$youtubePattern = '~\Ahttps://(?:www\.)?youtube\.com/watch\?v=[A-Za-z0-9_-]{11}\z|\Ahttps://youtu\.be/[A-Za-z0-9_-]{11}\z~D';
if (strlen($youtubeUrl) > 255 || !preg_match($youtubePattern, $youtubeUrl)) {
    fail('Use a youtube.com/watch?v= link or youtu.be link with an 11-character video ID.');
}
$episode['youtube_url'] = $youtubeUrl;

$rawCoverUrl = $body['cover_image_url'] ?? null;
if (!is_string($rawCoverUrl)) {
    fail('A Cloudinary cover image URL is required.');
}
$coverUrl = trim($rawCoverUrl);
$cloudName = trim((string)(getenv('CLOUDINARY_CLOUD_NAME') ?: ''));
if ($cloudName === '' || !preg_match('/\A[A-Za-z0-9_-]+\z/', $cloudName)) {
    fail('Episode image uploads are not configured on the server.', 503);
}
$cover = filter_var($coverUrl, FILTER_VALIDATE_URL) ? parse_url($coverUrl) : false;
if (
    strlen($coverUrl) > 500
    || !is_array($cover)
    || ($cover['scheme'] ?? '') !== 'https'
    || strtolower((string)($cover['host'] ?? '')) !== 'res.cloudinary.com'
    || isset($cover['port'])
    || isset($cover['user'])
    || isset($cover['pass'])
    || !preg_match('~\A/' . preg_quote($cloudName, '~') . '/.+\z~', (string)($cover['path'] ?? ''))
) {
    fail('Cover image must be hosted in the configured Cloudinary cloud.');
}
$episode['cover_image_url'] = $coverUrl;

$rawPublishDate = $body['publish_date'] ?? null;
if (!is_string($rawPublishDate)) {
    fail('Publish date must be a valid date in YYYY-MM-DD format.');
}
$publishDate = $rawPublishDate;
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $publishDate);
$dateErrors = DateTimeImmutable::getLastErrors();
if (!$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $publishDate) {
    fail('Publish date must be a valid date in YYYY-MM-DD format.');
}
$episode['publish_date'] = $publishDate;

$insert = db()->prepare('
    INSERT INTO episodes (episode_number, title, guest_name, category, description, youtube_url, cover_image_url, publish_date)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
');
try {
    $insert->execute([
        $episode['episode_number'], $episode['title'], $episode['guest_name'],
        $episode['category'], $episode['description'], $episode['youtube_url'],
        $episode['cover_image_url'], $episode['publish_date'],
    ]);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') {
        fail('That episode number already exists.', 409);
    }
    throw $error;
}

$episode['id'] = (int)db()->lastInsertId();
respond(['episode' => $episode], 201);
