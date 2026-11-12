<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Principal;
use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\Settings;
use App\Support\Persian;

/**
 * What a panel's user keeps for themselves, on every device: their favorites in the settings search, in their order —
 * the owner's one list whatever shop is open (kept with the main shop), an agent's with their own shop. The ids are the
 * panel's (`page:/plans`, `section:/bot-settings/wallet`, `setting:/bot-settings/wallet#wallet_topup_min`): one the panel
 * no longer knows is kept as it is, and left out there.
 */
final class PanelPreferences
{
    public const FAVORITES = 'panel.favorites';

    /** The most favorites a list keeps. */
    public const MOST = 100;

    private const ID = '~^(page|section|setting):/\S*$~u';
    private const ID_LENGTH = 200;

    private const NOT_A_LIST = 'فهرست علاقه‌مندی‌ها درست فرستاده نشده؛ صفحه را دوباره باز کنید.';
    private const TOO_MANY = 'حداکثر %s مورد را می‌توانید به علاقه‌مندی‌ها اضافه کنید.';

    public function __construct(private readonly Settings $settings) {}

    /** @return array{favorites: list<string>} */
    public function present(Principal $principal): array
    {
        $stored = $this->settings->get(self::FAVORITES, [], self::shopOf($principal));

        return ['favorites' => is_array($stored) ? array_values(array_filter($stored, self::valid(...))) : []];
    }

    /**
     * The favorites as the panel sends them — the whole list, in the user's order; an id twice is kept once.
     *
     * @return array{favorites: list<string>}
     * @throws ValidationException on `favorites`: not a list of the panel's ids, or longer than MOST
     */
    public function saveFavorites(Principal $principal, mixed $favorites): array
    {
        if (!is_array($favorites) || !array_is_list($favorites) || array_filter($favorites, static fn(mixed $id): bool => !self::valid($id)) !== []) {
            throw ValidationException::on('favorites', self::NOT_A_LIST);
        }
        /** @var list<string> $favorites */
        $list = array_values(array_unique($favorites));
        if (count($list) > self::MOST) {
            throw ValidationException::on('favorites', sprintf(self::TOO_MANY, Persian::number(self::MOST)));
        }

        if ($list === []) {
            $this->settings->forget(self::FAVORITES, self::shopOf($principal));
        } else {
            $this->settings->set(self::FAVORITES, $list, self::shopOf($principal));
        }

        return ['favorites' => $list];
    }

    /** Where the user's own are kept: the owner's with the main shop, whatever shop is open; an agent's with theirs. */
    private static function shopOf(Principal $principal): int
    {
        return $principal->kind === PrincipalKind::Owner ? Bot::MAIN : $principal->shop->id;
    }

    private static function valid(mixed $id): bool
    {
        return is_string($id) && mb_strlen($id) <= self::ID_LENGTH && preg_match(self::ID, $id) === 1;
    }
}
