<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\CurrentPrincipal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Support\Text;
use Illuminate\Database\Eloquent\Builder;

/**
 * A decision's `reviewer` as its reader sees it (CurrentPrincipal). The owner sees it as kept; anyone else sees the
 * owner's login as «پشتیبانی» — knowing it would let anyone lock the owner out (sign-in failures are counted per
 * username). Agents and admins show as kept (`@bot`, `@handle`, `tg:<id>`, `user#<id>`, `agent#<id>`); an admin's name
 * is read beside it where a screen shows who wrote something (names()).
 */
final class Reviewers
{
    /** What anyone but the owner reads for the owner. */
    public const SUPPORT = 'پشتیبانی';

    /** How agents and admins are kept; anything else is a login of the owner's, now or in the past. */
    private const OWN = '/^(@\S+|tg:\d+|user#\d+|agent#\d+)$/';

    /** How a shop's admin is kept (forAdmin()), by what names their account: an agent's @bot reads as a handle no account has. */
    private const ADMIN = '/^(?:@(?<handle>\S+)|tg:(?<telegram>\d+)|user#(?<id>\d+))$/';

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

    /**
     * The names of the shop's admins these reviewers are (forAdmin()), by reviewer, read at once: their account's first
     * and last name. A handle names an admin of the shop now (another customer may have taken an old one); a number,
     * whoever's it is. The owner, an agent's bot, an account gone or one that shows no name are left out.
     *
     * @param iterable<?string> $reviewers
     * @return array<string, string>
     */
    public function names(iterable $reviewers): array
    {
        // Each admin's reviewer by what it names them by: their handle, their Telegram id, their number.
        $handles = $telegramIds = $ids = [];
        foreach ($reviewers as $reviewer) {
            if ($reviewer === null || $this->shared($reviewer) !== $reviewer || preg_match(self::ADMIN, $reviewer, $named) !== 1) {
                continue;
            }
            if ($named['handle'] !== '') {
                $handles[$named['handle']] = $reviewer;
            } elseif (($named['telegram'] ?? '') !== '') {
                $telegramIds[$named['telegram']] = $reviewer;
            } else {
                $ids[$named['id']] = $reviewer;
            }
        }
        if ($handles === [] && $telegramIds === [] && $ids === []) {
            return [];
        }

        $accounts = User::query()
            ->where(static fn(Builder $query) => $query
                ->whereIn('id', array_keys($ids))
                ->orWhereIn('telegram_id', array_keys($telegramIds))
                ->orWhere(static fn(Builder $admins) => $admins->where('role', UserRole::Admin->value)->whereIn('username', array_map(strval(...), array_keys($handles)))))
            ->get();

        $names = [];
        foreach ($accounts as $account) {
            $name = $account->name();
            if ($name === null || Text::blank($name)) {
                continue;
            }
            $handle = $account->isAdmin() ? $handles[$account->username ?? ''] ?? null : null;
            foreach ([$ids[$account->id] ?? null, $telegramIds[$account->telegram_id ?? 0] ?? null, $handle] as $reviewer) {
                if ($reviewer !== null) {
                    $names[$reviewer] = $name;
                }
            }
        }

        return $names;
    }
}
