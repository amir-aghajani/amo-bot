<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Subscriptions\Enums\MoveStep;

/**
 * A move to another server that did not happen, and where it stopped (`step`). Nothing changed in any case. A panel that
 * failed is a 502 under its step (`errors.previous` is the screen's cue to offer leaving that server out), the service's
 * own state a 422 on `status`. A panel's failure is said in a word (ProviderErrorPresenter::summary()) until whoever
 * answers the screen words it for its reader (worded()): nothing of the panel reaches anyone it was not worded for.
 */
final class MoveException extends DomainRuleException
{
    private function __construct(
        public readonly MoveStep $step,
        /** The server it names — the previous one, or the target —; null for the service's own state. */
        private readonly ?string $server,
        string $reason,
        /** The panel's failure behind it, which worded() words for its reader. */
        private readonly ?ProviderException $panel = null,
    ) {
        parent::__construct(self::text($step, $server, $reason), 0, $panel);
    }

    public static function previousServer(Server $server, ProviderException $e): self
    {
        return new self(MoveStep::Previous, $server->name, ProviderErrorPresenter::summary($e), $e);
    }

    /** The target could not take the service: its panel failed, or a reason of the shop's own (`$why`). */
    public static function targetServer(Server $server, ProviderException|string $why): self
    {
        return $why instanceof ProviderException
            ? new self(MoveStep::Target, $server->name, ProviderErrorPresenter::summary($why), $why)
            : new self(MoveStep::Target, $server->name, $why);
    }

    public static function service(string $message): self
    {
        return new self(MoveStep::Service, null, $message);
    }

    /**
     * The same failure, its panel's said in `$words`' words — the reader's (the owner the diagnosis, anyone else the
     * word) —; one no panel failed is as it was.
     *
     * @param \Closure(ProviderException): string $words
     */
    public function worded(\Closure $words): self
    {
        return $this->panel === null ? $this : new self($this->step, $this->server, $words($this->panel), $this->panel);
    }

    public function status(): int
    {
        return $this->step === MoveStep::Service ? 422 : 502;
    }

    protected function field(): string
    {
        return $this->step === MoveStep::Service ? 'status' : $this->step->value;
    }

    /** «سرور قبلی «آلمان»: …», «سرور مقصد «هلند»: …» — or the service's own state's words alone. */
    private static function text(MoveStep $step, ?string $server, string $reason): string
    {
        return match ($step) {
            MoveStep::Previous => "سرور قبلی «{$server}»: {$reason}",
            MoveStep::Target => "سرور مقصد «{$server}»: {$reason}",
            MoveStep::Service => $reason,
        };
    }
}
