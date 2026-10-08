<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

/**
 * The driver's panel has no such operation (host statistics, credential rotation): see
 * PanelDriver::capabilities() before asking.
 */
final class UnsupportedOperationException extends ProviderException {}
