<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

/**
 * A thing that cannot be written now but may be in a moment — its panel did not answer this step (ServiceWriter): the
 * step stops before it, its cursor where it was, and the next step tries it again.
 */
final class TryAgain extends \RuntimeException {}
