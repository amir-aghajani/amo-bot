<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

/**
 * The panel has no such client — however it words it. Asked to delete one, the client is gone already: callers count
 * it as done (ProviderInterface::deleteClient()).
 */
final class NotFoundException extends ProviderException {}
