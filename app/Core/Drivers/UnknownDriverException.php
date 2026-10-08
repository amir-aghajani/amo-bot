<?php

declare(strict_types=1);

namespace App\Core\Drivers;

/** A key no driver of the family has: the code's — or a hand-edited config.php's — mistake, never a request's. */
final class UnknownDriverException extends \LogicException {}
