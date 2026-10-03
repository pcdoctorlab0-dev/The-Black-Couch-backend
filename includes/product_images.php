<?php
declare(strict_types=1);

function public_product_image_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    $baseUrl = rtrim(getenv('PUBLIC_BACKEND_URL') ?: 'http://localhost:8000', '/');
    return $baseUrl . '/' . ltrim($path, '/');
}
