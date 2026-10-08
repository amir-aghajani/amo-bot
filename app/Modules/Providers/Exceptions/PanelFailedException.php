<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The request needed a panel and the panel failed it: a 502 in words for whoever asked (ProviderErrorPresenter — the
 * owner's diagnosis, or the summary an agent may read), repeated under `errors.panel`: a screen's cue to offer going on
 * without that panel.
 */
final class PanelFailedException extends DomainRuleException
{
    public function __construct(string $message, ProviderException $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return 502;
    }

    protected function field(): string
    {
        return 'panel';
    }
}
