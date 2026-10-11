<?php

declare(strict_types=1);

namespace App\Core\Drivers;

/** A key no driver of the family has: a mistake of the code or of a hand-edited config.php — requests are validated first. */
final class UnknownDriverException extends \LogicException {}
