<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\DTO\CustomPrice;
use App\Modules\Catalog\DTO\Package;
use App\Modules\Catalog\DTO\PriceTiers;
use App\Modules\Catalog\Enums\CustomQuantity;
use App\Modules\Providers\Models\Server;
use App\Support\Persian;

/**
 * «سرویس دلخواه»: a customer builds a service of the gigabytes and the days they choose, on one of the builder's servers,
 * with the builder's devices — bought as any purchase, without a plan (OrderService::openCustomPurchase(), delivered on
 * the server whole: ServerSelector::builtOn()). Each quantity is priced whole at its tier's rate and the two prices are
 * added (quote()); the customer's discounts come off that (Orders\Services\Pricing::custom()).
 *
 * The main shop's alone, off by default (CustomServiceSettings). It is offered while it is switched on, both price lists
 * sell, and a server of the builder's can take a client now. A quantity may be built from its least to its most — the
 * least never below its first tier, where its prices start —, and the buttons offer the amounts set within those bounds:
 * each quantity (Enums\CustomQuantity) by the same methods (tiers(), bounds(), options(), allowed(), price()).
 */
final class CustomServices
{
    /** The builder not offered now: the website's refusal, and the bot's default words (BotText::CustomUnavailable). */
    public const OFF = 'ساخت سرویس دلخواه الان در دسترس نیست.';

    /** An amount outside its bounds: the quantity's noun, its least, its most, its unit (outside()). */
    public const OUTSIDE = '%s را عددی بین %s تا %s %s بنویسید.';

    public const PICK_A_SERVER = 'سرور را انتخاب کنید.';
    public const SERVER_UNAVAILABLE = 'این سرور برای سرویس دلخواه در دسترس نیست.';

    public function __construct(
        private readonly CustomServiceSettings $settings,
        private readonly ServerSelector $selector,
    ) {}

    /** Whether customers may build a service now: switched on in the main shop, both lists priced, a server able to take it. */
    public function offered(): bool
    {
        return $this->servers() !== [];
    }

    /**
     * The servers a customer may build on now, in the servers' order: the builder's that can take a client
     * (ServerSelector::wholeServer()). None while the builder is not switched on and priced.
     *
     * @return list<Server>
     */
    public function servers(): array
    {
        $ids = $this->open() ? $this->settings->serverIds() : [];
        if ($ids === []) {
            return [];
        }
        $servers = Server::query()->whereKey($ids)->withCount(Server::activeServicesCount())->with('inbounds')->orderBy('sort')->orderBy('id')->get();

        return array_values(array_filter($servers->all(), fn(Server $server): bool => $this->selector->wholeServer($server) !== null));
    }

    /**
     * The server the customer picked among servers(); with none named, the only one there is.
     *
     * @throws ValidationException 422 on `status` while nothing is offered; on `server_id` for none picked among several,
     *                             or a server not on offer now
     */
    public function server(?int $serverId): Server
    {
        $servers = $this->servers();
        if ($servers === []) {
            throw ValidationException::on('status', self::OFF);
        }
        if ($serverId === null) {
            return count($servers) === 1 ? $servers[0] : throw ValidationException::on('server_id', self::PICK_A_SERVER);
        }
        foreach ($servers as $server) {
            if ($server->id === $serverId) {
                return $server;
            }
        }

        throw ValidationException::on('server_id', self::SERVER_UNAVAILABLE);
    }

    /** The quantity's tiers as a customer meets them (tiersWithin()): within bounds(), each from where it starts there. */
    public function tiers(CustomQuantity $quantity): PriceTiers
    {
        return self::tiersWithin($this->settings->tiers($quantity), $this->bounds($quantity));
    }

    /** @return array{int, int} The least and the most of the quantity a customer may build: the bounds set, from the first tier on. */
    public function bounds(CustomQuantity $quantity): array
    {
        [$least, $most] = $this->settings->bounds($quantity);

        return [max($least, $this->settings->tiers($quantity)->tiers[0]['from'] ?? $least), $most];
    }

    /** @return list<int> The amounts of the quantity offered as buttons: those set, within bounds(). */
    public function options(CustomQuantity $quantity): array
    {
        return array_values(array_filter($this->settings->options($quantity), fn(int $amount): bool => $this->allowed($quantity, $amount)));
    }

    /** Whether `$amount` of the quantity may be built: within bounds(), where a tier prices it. */
    public function allowed(CustomQuantity $quantity, int $amount): bool
    {
        [$least, $most] = $this->bounds($quantity);

        return $amount >= $least && $amount <= $most;
    }

    /**
     * What `$amount` of the quantity costs, whole at its tier's rate, while it is allowed().
     *
     * @throws ValidationException 422 on the quantity's field (`gb`, `days`), saying the bounds (outside())
     */
    public function price(CustomQuantity $quantity, int $amount): string
    {
        $price = $this->allowed($quantity, $amount) ? $this->settings->tiers($quantity)->priceOf($amount) : null;

        return $price ?? throw ValidationException::on($quantity->field(), $this->outside($quantity));
    }

    /**
     * What `$gb` GB for `$days` days cost before any discount: each within its bounds, priced whole at its tier's rate.
     *
     * @throws ValidationException 422 on `gb` or `days`, saying the bounds
     */
    public function quote(int $gb, int $days): CustomPrice
    {
        return new CustomPrice($gb, $days, $this->price(CustomQuantity::Traffic, $gb), $this->price(CustomQuantity::Days, $days));
    }

    /** Devices at once on a built service; 0 = unlimited. */
    public function devices(): int
    {
        return $this->settings->devices();
    }

    /** What a service built of `$gb` GB for `$days` days gives: its term, its traffic and the builder's devices. */
    public function package(int $gb, int $days): Package
    {
        return Package::built($gb, $days, $this->settings->devices());
    }

    /** Switched on in the main shop, with both lists priced and something to build in each — servers not yet asked. */
    private function open(): bool
    {
        if (!CurrentBot::isMain() || !$this->settings->enabled()) {
            return false;
        }
        foreach (CustomQuantity::cases() as $quantity) {
            [$least, $most] = $this->bounds($quantity);
            if (!$this->settings->tiers($quantity)->sells() || $least > $most) {
                return false;
            }
        }

        return true;
    }

    /** The refusal of an amount of the quantity outside its bounds, in words: «حجم را عددی بین ۱ تا ۵۰۰ گیگابایت بنویسید.». */
    private function outside(CustomQuantity $quantity): string
    {
        [$least, $most] = $this->bounds($quantity);

        return sprintf(self::OUTSIDE, $quantity->noun(), Persian::number($least), Persian::number($most), $quantity->unit());
    }

    /**
     * The tiers that price something within `$bounds`, each from where it starts there — the same price for every
     * quantity within them, without a tier the bounds leave out or one starting below the least.
     *
     * @param array{int, int} $bounds
     */
    private static function tiersWithin(PriceTiers $tiers, array $bounds): PriceTiers
    {
        $within = [];
        foreach ($tiers->tiers as $i => $tier) {
            $from = max($tier['from'], $bounds[0]);
            $until = min(($tiers->tiers[$i + 1]['from'] ?? PHP_INT_MAX) - 1, $bounds[1]);
            if ($from <= $until) {
                $within[] = ['from' => $from, 'price' => $tier['price']];
            }
        }

        return new PriceTiers($within);
    }
}
