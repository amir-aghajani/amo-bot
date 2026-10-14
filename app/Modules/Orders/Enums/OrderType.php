<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** What an order buys. */
enum OrderType: string
{
    case Purchase = 'purchase';         // a new service from a plan
    case Renewal = 'renewal';           // another term of a service the customer has
    case WalletTopUp = 'wallet_topup';  // Toman for the wallet
    case Traffic = 'traffic';           // an agent's traffic, for the services their own bot sells (main bot only)
    case ExtraTraffic = 'extra_traffic'; // traffic bought on top of a service the customer has
    case ExtraTime = 'extra_time';       // days bought on top of a service the customer has
    case ExtraDevices = 'extra_devices'; // devices (its IP limit) bought on top of a service the customer has

    /**
     * What a customer buys for themselves: what discounts apply to, and what loyalty, cashback and the nightly draw count.
     * Never a wallet top-up (its amount is what it buys) nor an agent's traffic (their level's price is the price).
     */
    public const FOR_THEMSELVES = [self::Purchase->value, self::Renewal->value, self::ExtraTraffic->value, self::ExtraTime->value, self::ExtraDevices->value];

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'خرید',
            self::Renewal => 'تمدید',
            self::WalletTopUp => 'شارژ کیف پول',
            self::Traffic => 'خرید حجم نمایندگی',
            self::ExtraTraffic => 'حجم اضافه',
            self::ExtraTime => 'زمان اضافه',
            self::ExtraDevices => 'کاربر اضافه',
        };
    }

    /** Whether the customer buys it for themselves (FOR_THEMSELVES). */
    public function forThemselves(): bool
    {
        return in_array($this->value, self::FOR_THEMSELVES, true);
    }

    /** FOR_THEMSELVES in words, for a refusal: «خرید، تمدید، حجم اضافه، زمان اضافه یا کاربر اضافه». */
    public static function forThemselvesInWords(): string
    {
        return self::either(array_map(static fn(string $type): string => self::from($type)->label(), self::FOR_THEMSELVES));
    }

    /**
     * Types as an API takes them, for its refusal: «purchase، renewal یا extra_traffic».
     *
     * @param list<self> $types
     */
    public static function valuesInWords(array $types): string
    {
        return self::either(array_map(static fn(self $type): string => $type->value, $types));
    }

    /** @param list<string> $words One of them, in words: «الف، ب یا ج». */
    private static function either(array $words): string
    {
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode('، ', $words) . ' یا ' . $last;
    }

    /**
     * An order for a service the customer already has — a renewal, or extra traffic, time or devices —: its
     * `subscription_id` is set from the start and its server is the service's.
     */
    public function forService(): bool
    {
        return match ($this) {
            self::Renewal, self::ExtraTraffic, self::ExtraTime, self::ExtraDevices => true,
            self::Purchase, self::WalletTopUp, self::Traffic => false,
        };
    }
}
