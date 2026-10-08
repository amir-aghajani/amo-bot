<?php

declare(strict_types=1);

namespace App\Modules\Agency\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** Something an agent asked for was refused; the message is Persian, for them to read. */
final class AgencyException extends DomainRuleException {}
