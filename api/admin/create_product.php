<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/product_images.php';

require_method('POST');
require_admin();
require_csrf();

$name = trim((string)($_POST['name'] ?? ''));
$type = trim((string)($_POST['type'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$priceRaw = $_POST['price_cents'] ?? null;
$priceCents = filter_var($priceRaw, FILTER_VALIDATE_INT);
if ($name === '' || strlen($name) > 150) {
    fail('Product name is required and must be no more than 150 characters.');
}
if ($type === '' || strlen($type) > 60) {
    fail('Product type is required and must be no more than 60 characters.');
}
if ($description === '' || strlen($description) > 600) {
    fail('Product description is required and must be no more than 600 characters.');
}
if ($priceCents === false || $priceCents <= 0) {
    fail('price_cents must be a positive integer.');
}

$variantsJson = (string)($_POST['variants'] ?? '');
try {
    $variants = json_decode($variantsJson, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fail('variants must be a valid JSON array.');
}
if (!is_array($variants) || !array_is_list($variants) || count($variants) === 0) {
    fail('At least one product variant is required.');
}
$cleanVariants = [];
$seenSizes = [];
foreach ($variants as $variant) {
    if (!is_array($variant) || !is_string($variant['size'] ?? null) || !is_int($variant['stock'] ?? null)) {
        fail('Each variant must include a size and integer stock.');
    }
    $size = trim($variant['size']);
    $stock = $variant['stock'];
    if ($size === '' || strlen($size) > 20 || $stock < 0 || $stock > 2147483647) {
        fail('Variant sizes must be 1-20 characters and stock must be non-negative.');
    }
    $sizeKey = strtolower($size);
    if (isset($seenSizes[$sizeKey])) {
        fail('Variant sizes must be unique.');
    }
    $seenSizes[$sizeKey] = true;
    $cleanVariants[] = ['size' => $size, 'stock' => $stock];
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    fail('A product image is required.');
}
$imageFile = $_FILES['image'];
if ((int)$imageFile['size'] <= 0 || (int)$imageFile['size'] > 5 * 1024 * 1024) {
    fail('Product image must be no larger than 5MB.');
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($imageFile['tmp_name']);
$allowedImages = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$imageInfo = @getimagesize($imageFile['tmp_name']);
if (!isset($allowedImages[$detectedMime]) || !$imageInfo || $imageInfo['mime'] !== $detectedMime) {
    fail('Product image must be a valid JPG, PNG or WEBP file.');
}

$imageDir = __DIR__ . '/../../storage/product-images';
if (!is_dir($imageDir) && !mkdir($imageDir, 0750, true) && !is_dir($imageDir)) {
    fail('Could not prepare product image storage.', 500);
}
$filename = bin2hex(random_bytes(24)) . '.' . $allowedImages[$detectedMime];
$destination = $imageDir . '/' . $filename;
if (!move_uploaded_file($imageFile['tmp_name'], $destination)) {
    fail('Could not save the product image.', 500);
}
chmod($destination, 0644);
$imagePath = 'storage/product-images/' . $filename;

$pdo = db();
$pdo->beginTransaction();
try {
    $insertProduct = $pdo->prepare('INSERT INTO products (name, type, description, price_cents, image) VALUES (?, ?, ?, ?, ?)');
    $insertProduct->execute([$name, $type, $description, $priceCents, $imagePath]);
    $productId = (int)$pdo->lastInsertId();

    $insertVariant = $pdo->prepare('INSERT INTO product_variants (product_id, size, stock) VALUES (?, ?, ?)');
    foreach ($cleanVariants as $variant) {
        $insertVariant->execute([$productId, $variant['size'], $variant['stock']]);
    }

    $variantsStmt = $pdo->prepare('SELECT id AS variant_id, size, stock FROM product_variants WHERE product_id = ? ORDER BY id');
    $variantsStmt->execute([$productId]);
    $savedVariants = $variantsStmt->fetchAll();
    foreach ($savedVariants as &$savedVariant) {
        $savedVariant['variant_id'] = (int)$savedVariant['variant_id'];
        $savedVariant['stock'] = (int)$savedVariant['stock'];
    }
    unset($savedVariant);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($destination);
    if ($error instanceof PDOException && $error->getCode() === '23000') {
        fail('One or more variant sizes conflict with the database.');
    }
    throw $error;
}

respond(['product' => [
    'id' => $productId,
    'name' => $name,
    'type' => $type,
    'description' => $description,
    'price_cents' => (int)$priceCents,
    'image' => public_product_image_url($imagePath),
    'variants' => $savedVariants,
]], 201);
