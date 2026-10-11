<?php

declare(strict_types=1);

namespace App\Core\Security;

/** A payload that does not decrypt: malformed, tampered with, or written under another APP_KEY. */
final class DecryptionException extends \RuntimeException {}
