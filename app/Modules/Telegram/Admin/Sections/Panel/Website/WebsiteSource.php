<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/**
 * The website's settings («تنظیمات وب‌سایت»: GET /website) as its cards' fields, by the names PATCH /website takes them
 * under — the switches flat, the other origins one a line, the Client Secret as whether one is kept, each staff grant a
 * switch (`grant_<grant>`) —, and the website itself under `website` for what a card shows besides (its API address,
 * whether email or SMS goes out). A field is saved alone, as the panel's card saves its own: a grant as the whole list in
 * the grants' order, a secret emptied as its `clear_`, a text emptied as ''.
 */
final class WebsiteSource implements SettingsSource
{
    /** The Client Secret, the one secret the cards keep. */
    public const SECRET = 'telegram_client_secret';

    /** What a staff grant's switch is called among the fields: `grant_` and the grant. */
    public const GRANT = 'grant_';

    public function load(Admin $admin): array
    {
        return self::flat($admin->api->get('/website'));
    }

    public function save(Admin $admin, array $values, string $field, mixed $value): array
    {
        $body = match (true) {
            $field === 'origins' => ['origins' => self::origins(is_string($value) ? $value : '')],
            $field === self::SECRET => $value === null || $value === '' ? ['clear_' . self::SECRET => true] : [self::SECRET => $value],
            str_starts_with($field, self::GRANT) => ['staff_grants' => self::grants($values, substr($field, strlen(self::GRANT)), (bool) $value)],
            default => [$field => $value ?? ''],
        };

        return self::flat($admin->api->patch('/website', $body));
    }

    /**
     * The website as the cards read it.
     *
     * @param array<string, mixed> $answer GET or PATCH /website's
     * @return array<string, mixed>
     */
    public static function flat(array $answer): array
    {
        $website = Json::part($answer, 'website');
        $part = static fn(string $key): array => is_array($website[$key] ?? null) ? $website[$key] : [];
        $staff = $part('staff');
        $grants = Json::part($staff, 'grants');
        $values = [
            'website' => $website,
            'enabled' => Json::on($website, 'enabled'),
            'url' => is_string($website['url'] ?? null) ? $website['url'] : '',
            'origins' => implode("\n", array_filter(Json::part($website, 'origins'), is_string(...))),
            'telegram_login' => ($part('telegram')['enabled'] ?? false) === true,
            'telegram_client_id' => is_string($part('telegram')['client_id'] ?? null) ? $part('telegram')['client_id'] : '',
            self::SECRET => ($part('telegram')['has_secret'] ?? false) === true,
            'google_client_id' => is_string($part('google')['client_id'] ?? null) ? $part('google')['client_id'] : '',
            'email_signup' => ($part('email')['enabled'] ?? false) === true,
            'phone_login' => ($part('phone')['enabled'] ?? false) === true,
            'reviews_enabled' => ($part('reviews')['enabled'] ?? false) === true,
            'staff_enabled' => Json::on($staff, 'enabled'),
            'staff_strong_sign_in' => Json::on($staff, 'strong_sign_in'),
        ];
        foreach (StaffGrant::cases() as $grant) {
            $values[self::GRANT . $grant->value] = in_array($grant->value, $grants, true);
        }

        return $values;
    }

    /**
     * The origins typed — one a line, or apart by commas or spaces, as the API reads typed text —, blanks dropped.
     *
     * @return list<string>
     */
    public static function origins(string $typed): array
    {
        return array_values(array_filter(preg_split('/[\s,،]+/u', $typed) ?: [], static fn(string $origin): bool => $origin !== ''));
    }

    /**
     * The grants with `$grant` switched, in the grants' own order (the API keeps them so).
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private static function grants(array $values, string $grant, bool $on): array
    {
        $grants = [];
        foreach (StaffGrant::cases() as $case) {
            if ($case->value === $grant ? $on : ($values[self::GRANT . $case->value] ?? false) === true) {
                $grants[] = $case->value;
            }
        }

        return $grants;
    }
}
