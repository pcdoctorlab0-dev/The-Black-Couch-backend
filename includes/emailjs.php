<?php
declare(strict_types=1);

final class EmailDeliveryUnavailable extends RuntimeException
{
}

function emailjs_send(string $templateId, array $templateParams): void
{
    $serviceId = trim((string)(getenv('EMAILJS_SERVICE_ID') ?: ''));
    $publicKey = trim((string)(getenv('EMAILJS_PUBLIC_KEY') ?: ''));
    $privateKey = trim((string)(getenv('EMAILJS_PRIVATE_KEY') ?: ''));

    if (
        $serviceId === ''
        || $publicKey === ''
        || $privateKey === ''
        || trim($templateId) === ''
        || str_starts_with($privateKey, 'ROTATE_')
        || str_starts_with($privateKey, 'replace-')
    ) {
        throw new EmailDeliveryUnavailable('Email delivery is not configured.');
    }

    emailjs_reserve_quota();

    try {
        $payload = json_encode([
            'service_id' => $serviceId,
            'template_id' => $templateId,
            'user_id' => $publicKey,
            'accessToken' => $privateKey,
            'template_params' => $templateParams,
        ], JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new EmailDeliveryUnavailable('Could not prepare the email request.', 0, $error);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents('https://api.emailjs.com/api/v1.0/email/send', false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }

    if ($response === false || $status < 200 || $status >= 300) {
        error_log('EmailJS delivery failed with HTTP status ' . ($status ?: 'unavailable') . '.');
        throw new EmailDeliveryUnavailable('Email service is temporarily unavailable.');
    }
}

function emailjs_reserve_quota(): void
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $select = $pdo->prepare('SELECT remaining_requests, cycle_limit, reset_at_utc FROM emailjs_quota WHERE id = ? FOR UPDATE');
        $select->execute([1]);
        $quota = $select->fetch();
        if (!$quota) {
            throw new RuntimeException('EmailJS quota row is not initialized.');
        }

        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        $resetAt = new DateTimeImmutable((string)$quota['reset_at_utc'], $utc);
        $cycleLimit = (int)$quota['cycle_limit'];
        $remaining = (int)$quota['remaining_requests'];
        if ($now >= $resetAt) {
            do {
                $resetAt = $resetAt->modify('+1 month');
            } while ($resetAt <= $now);
            $remaining = $cycleLimit;
        }

        if ($remaining <= 0) {
            $updateReset = $pdo->prepare('UPDATE emailjs_quota SET reset_at_utc = ? WHERE id = ?');
            $updateReset->execute([$resetAt->format('Y-m-d H:i:s'), 1]);
            $pdo->commit();
            throw new EmailDeliveryUnavailable('Email request quota exhausted until the next reset.');
        }

        $update = $pdo->prepare('UPDATE emailjs_quota SET remaining_requests = ?, reset_at_utc = ? WHERE id = ?');
        $update->execute([
            $remaining - 1,
            $resetAt->format('Y-m-d H:i:s'),
            1,
        ]);
        $pdo->commit();
    } catch (EmailDeliveryUnavailable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('EmailJS quota reservation failed.');
        throw new EmailDeliveryUnavailable('Email service is temporarily unavailable.', 0, $error);
    }
}