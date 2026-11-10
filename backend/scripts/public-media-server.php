<?php

/**
 * Minimal static server for post media, for local Instagram testing through an HTTPS tunnel.
 *
 *   php -S 127.0.0.1:8001 scripts/public-media-server.php      (run from the backend/ folder)
 *   cloudflared tunnel --url http://127.0.0.1:8001             (prints https://<random>.trycloudflare.com)
 *   PUBLIC_MEDIA_URL=https://<random>.trycloudflare.com         (in backend/.env, then restart the API)
 *
 * Why a separate process: `php artisan serve` handles one request at a time on Windows, and Meta downloads
 * the image *while* our API request is still waiting for Meta's answer. If the same process had to serve the
 * image, the download would block and Instagram would report "Media download has failed".
 *
 * Only GET/HEAD requests for /storage/posts/<...> image or video files are served. Everything else is 404, so
 * the tunnel never exposes the API, .env, logs, or any other file.
 */

$root = realpath(__DIR__ . '/../storage/app/public/posts');

$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'mp4'  => 'video/mp4',
    'mov'  => 'video/quicktime',
];

$notFound = static function (int $status = 404): bool {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $status === 405 ? 'Method Not Allowed' : 'Not Found';
    return true;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    return $notFound(405);
}

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($root === false || !str_starts_with($path, '/storage/posts/') || str_contains($path, "\0")) {
    return $notFound();
}

$file = realpath($root . '/' . substr($path, strlen('/storage/posts/')));
$normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
if ($file === false || !is_file($file) || !str_starts_with(str_replace('\\', '/', $file), $normalizedRoot)) {
    return $notFound();
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!isset($types[$ext])) {
    return $notFound();
}

http_response_code(200);
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

if ($method === 'GET') {
    readfile($file);
}

return true;
