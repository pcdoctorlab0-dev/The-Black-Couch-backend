<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

require_method('GET');

// No CSRF check here — this GET only hands out the token that later
// mutating requests must echo back, it doesn't perform any state change.
respond(['csrf_token' => csrf_token(), 'user' => current_user()]);
