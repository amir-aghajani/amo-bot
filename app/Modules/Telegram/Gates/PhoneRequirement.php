<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Gates;

use App\Modules\Telegram\BotSettings;
use App\Modules\Users\Models\User;

/**
 * The shop's phone rule, one answer for every door: while it is on (BotSettings::phoneRequired()), a customer without
 * a verified number is asked for it — before the bot serves anything at all (the whole bot, PhoneGate), or only at the
 * moments the shop chose (PhoneMoment: a purchase, a renewal, a free test, a card-to-card payment). Never asked: the
 * bot's admins, and a customer support excused (`users.phone_exempt`, set from the panels, the website's admin side or
 * the report group's card). While the shop takes Iranian numbers alone (BotSettings::phoneIranOnly()), another
 * country's is refused as it is sent (accepts()), and one on file from before counts as none: its customer is asked
 * again (foreign()).
 */
final class PhoneRequirement
{
    /** An Iranian number, as numbers are kept (User::verifyPhone(): "+98…"). */
    private const IRAN = '+98';

    public function __construct(private readonly BotSettings $settings) {}

    /** Whether the rule could ever ask `$user`: it is on, they have no number it takes, are no admin, nor excused. */
    public function applies(User $user): bool
    {
        return $this->settings->phoneRequired() && !$this->verified($user) && !$user->isAdmin() && !$user->phone_exempt;
    }

    /** Whether the rule takes `$number` (as a contact card or the row has it): any, or an Iranian one alone. */
    public function accepts(string $number): bool
    {
        return !$this->settings->phoneIranOnly() || str_starts_with('+' . preg_replace('/\D+/', '', $number), self::IRAN);
    }

    /** Whether `$user` has a number on file the rule does not take now: another country's, while Iranian ones alone count. */
    public function foreign(User $user): bool
    {
        return $user->phone !== null && !$this->accepts($user->phone);
    }

    /**
     * Whether `$user` is asked now, about to do what is one of `$moments` (none: anything else, so only while it is asked
     * before everything) — asked before everything, or `$moments` names one of the moments the shop chose.
     */
    public function asks(User $user, PhoneMoment ...$moments): bool
    {
        if (!$this->applies($user)) {
            return false;
        }
        if ($this->settings->phoneEverywhere()) {
            return true;
        }
        $chosen = $this->settings->phoneMoments();

        return array_filter($moments, static fn(PhoneMoment $moment): bool => in_array($moment, $chosen, true)) !== [];
    }

    /**
     * The moments the number is asked at, as the website and the mini app are told (`GET /` shop.phone_moments): none
     * while it is asked before everything, or not at all.
     *
     * @return list<string>
     */
    public function moments(): array
    {
        if (!$this->settings->phoneRequired() || $this->settings->phoneEverywhere()) {
            return [];
        }

        return array_map(static fn(PhoneMoment $moment): string => $moment->value, $this->settings->phoneMoments());
    }

    /** Whether `$user`'s number on file is one the rule takes. */
    private function verified(User $user): bool
    {
        return $user->hasVerifiedPhone() && !$this->foreign($user);
    }
}
