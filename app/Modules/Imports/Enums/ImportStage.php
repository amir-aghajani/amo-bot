<?php

declare(strict_types=1);

namespace App\Modules\Imports\Enums;

/**
 * The stages an import writes in, in this order: the servers its panels are added as, the customer groups, the
 * customers (then, once they all are, who brought whom), the plans, the services, the codes, the channels and the cards
 * — each thing after what it names.
 */
enum ImportStage: string
{
    case Servers = 'servers';
    case Groups = 'groups';
    case Customers = 'customers';
    case Referrals = 'referrals';
    case Plans = 'plans';
    case Services = 'services';
    case DiscountCodes = 'discount_codes';
    case GiftCodes = 'gift_codes';
    case Channels = 'channels';
    case Cards = 'cards';

    /** The stage after this one; null after the last. */
    public function next(): ?self
    {
        $cases = self::cases();
        $at = array_search($this, $cases, true);

        return $cases[$at + 1] ?? null;
    }

    /** In the owner's words, as the progress shows it. */
    public function label(): string
    {
        return match ($this) {
            self::Servers => 'سرورها',
            self::Groups => 'گروه‌های مشتری',
            self::Customers => 'مشتری‌ها',
            self::Referrals => 'معرفی‌ها',
            self::Plans => 'پلن‌ها',
            self::Services => 'سرویس‌ها',
            self::DiscountCodes => 'کدهای تخفیف',
            self::GiftCodes => 'کدهای هدیه',
            self::Channels => 'کانال‌های اجباری',
            self::Cards => 'کارت‌های بانکی',
        };
    }
}
