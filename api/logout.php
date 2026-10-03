<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('POST');
require_csrf();

log_out_user();
respond(['ok' => true]);
