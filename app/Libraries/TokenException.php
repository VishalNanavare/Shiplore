<?php

declare(strict_types=1);

namespace App\Libraries;

use RuntimeException;

/** Thrown when a token is malformed, tampered, wrongly-signed, or expired. */
final class TokenException extends RuntimeException {}
