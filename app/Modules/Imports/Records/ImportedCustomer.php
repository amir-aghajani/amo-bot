<?php

declare(strict_types=1);

namespace App\Modules\Imports\Records;

/**
 * A customer of the other bot. Matched to this shop by their Telegram account: a customer already here keeps what they
 * have, gaining what the import brings that they lack; anyone else becomes a customer quietly — no report, no referral
 * gift, as though they had always been here.
 */
final class ImportedCustomer
{
    public function __construct(
        /** The other bot's id for them: what its services, referrals and the import's links name them by. */
        public readonly string $key,
        public readonly int $telegramId,
        /** Their Telegram @username without the @; null for none. */
        public readonly ?string $username = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        /** Their mobile as the other bot kept it (the core keeps only a mobile Support\Mobile reads); null for none. */
        public readonly ?string $phone = null,
        public readonly bool $banned = false,
        /** Their wallet in Toman, a decimal string; below zero for one in debt. */
        public readonly string $balance = '0',
        /** A percent off everything they buy, theirs for good (users.discount_percent); 0 for none. */
        public readonly int $discountPercent = 0,
        /** The `$key` of the customer who brought them; null for none. */
        public readonly ?string $referrerKey = null,
        /** The customer group they join, by its name (one of Survey::$groups); null for none. */
        public readonly ?string $group = null,
        /** An admin of the other bot (Survey::$admins): made an admin here only if the owner says so. */
        public readonly bool $admin = false,
        public readonly ?\DateTimeImmutable $joinedAt = null,
        /** The other bot's reseller bot they belong to (its name); null for the bot itself. */
        public readonly ?string $reseller = null,
        /**
         * They bought in the other bot (a purchase it delivered, not a free test): no newcomer here — no first-purchase
         * price, no first-purchase-only code, no newcomers-only test, no referral start gift for their first purchase.
         */
        public readonly bool $boughtBefore = false,
        /** The other bot's admin excused them from giving a mobile: excused from the shop's phone rule (users.phone_exempt). */
        public readonly bool $phoneExempt = false,
    ) {}
}
