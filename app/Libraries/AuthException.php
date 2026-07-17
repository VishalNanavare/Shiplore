<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/** Thrown when a request/credential cannot be authenticated (→ 401 / login fail). */
final class AuthException extends RuntimeException {}
