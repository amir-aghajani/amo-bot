<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\CurrentPrincipal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Users\Models\User;

/**
 * A decision's `reviewer` as its reader sees it (CurrentPrincipal). The owner sees it as kept; anyone else sees the
 * owner's login as «پشتیبانی» — knowing it would let anyone lock the owner out (sign-in failures are counted per
 * username). Agents and admins show as kept (`@bot`, `@handle`, `tg:<id>`, `user#<id>`, `agent#<id>`).
 */
final class Reviewers
{
    /** What anyone but the owner reads for the owner. */
    public const SUPPORT = 'پشتیبانی';

    /** How agents and admins are kept; anything else is a login of the owner's, now or in the past. */
    private const OWN = '/^(@\S+|tg:\d+|user#\d+|agent#\d+)$/';

    public function __construct(private readonly AdminAccount $owner) {}

    /** A shop's admin as their decisions keep them: their @handle, else `tg:<telegram id>`, else `user#<id>`. */
    public static function forAdmin(User $admin): string
    {
        return match (true) {
            $admin->handle() !== null => mb_substr('@' . $admin->handle(), 0, 64),
            $admin->telegram_id !== null => 'tg:' . $admin->telegram_id,
            default => 'user#' . $admin->id,
        };
    }

    public function present(?string $reviewer): ?string
    {
        return CurrentPrincipal::get()?->kind === PrincipalKind::Owner ? $reviewer : $this->shared($reviewer);
    }

    /** The reviewer as anyone but the owner reads it, whoever reads it now: the report group, an agent, a website's admin. */
    public function shared(?string $reviewer): ?string
    {
        if ($reviewer === null) {
            return null;
        }

        // The login now is the owner's even when it looks like one of the shop's own (it may start with "@").
        return $reviewer === $this->owner->username() || preg_match(self::OWN, $reviewer) !== 1 ? self::SUPPORT : $reviewer;
    }
}
