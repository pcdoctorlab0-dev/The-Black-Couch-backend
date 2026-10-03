<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('GET');

$stmt = db()->prepare('
    SELECT id, episode_number, title, guest_name, category, description,
           youtube_url, cover_image_url, publish_date
    FROM episodes
    ORDER BY publish_date DESC, episode_number DESC, id DESC
');
$stmt->execute();
$episodes = $stmt->fetchAll();

foreach ($episodes as &$episode) {
    $episode['id'] = (int)$episode['id'];
    $episode['episode_number'] = (int)$episode['episode_number'];
}
unset($episode);

respond(['episodes' => $episodes]);