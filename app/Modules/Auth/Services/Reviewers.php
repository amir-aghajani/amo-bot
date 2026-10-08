<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\CurrentPrincipal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Users\Models\User;

/**
 * Who decided something — the `reviewer` a decision keeps: a payment's verdict, a traffic line's adjustment or
 * extension, an unpin started from a panel, a ticket's answer — as the shop's screens show it to whoever reads them
 * (CurrentPrincipal). The owner reads it as kept. Anyone else — an agent, one of the shop's admins on its website — reads
 * the owner's login as «پشتیبانی»: a login's name would let anyone lock the owner out of the panel (SignInThrottle counts
 * failed sign-ins per username). The shop's own people show as they are kept: the agent (`@bot`, `@handle` or
 * `agent#<id>`, AgentAuth) and its admins — in its report group or on its website — by their account (forAdmin()).
 */
final class Reviewers
{
    /** What anyone but the owner reads for the owner — the word everything a customer or an agent reads uses. */
    public const SUPPORT = 'پشتیبانی';

    /** How the shop's own people are kept; anything else is a login the owner signs in with, or once did. */
    private const OWN = '/^(@\S+|tg:\d+|user#\d+|agent#\d+)$/';

    public function __construct(private readonly AdminAccount $owner) {}

    /**
     * One of the shop's admins as a decision of theirs keeps them — in the report group, on the shop's website —: their
     * @handle, else their Telegram id (`tg:<id>`), else — an account of the website alone — its number (`user#<id>`).
     */
    public static function forAdmin(User $admin): string
    {
        return match (true) {
            ($admin->username ?? '') !== '' => mb_substr('@' . $admin->username, 0, 64),
            $admin->telegram_id !== null => 'tg:' . $admin->telegram_id,
            default => 'user#' . $admin->id,
        };
    }

    public function present(?string $reviewer): ?string
    {
        if ($reviewer === null || CurrentPrincipal::get()?->kind === PrincipalKind::Owner) {
            return $reviewer;
        }

        // The login now is the owner's even when it looks like one of the shop's own (it may start with "@").
        return $reviewer === $this->owner->username() || preg_match(self::OWN, $reviewer) !== 1 ? self::SUPPORT : $reviewer;
    }
}
