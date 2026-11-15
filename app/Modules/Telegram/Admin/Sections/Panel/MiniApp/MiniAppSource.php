<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\MiniApp;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/**
 * The mini app's settings (GET /mini-app's `mini_app`), saved a field at a time by PATCH /mini-app — a text emptied as
 * '', the colour in small letters as the API keeps it. The bot's menu button is set again with every save; when
 * Telegram does not take it (`menu_button_error`) the save still stood, and a popup says so.
 */
final class MiniAppSource implements SettingsSource
{
    public function load(Admin $admin): array
    {
        return self::app($admin->api->get('/mini-app'));
    }

    public function save(Admin $admin, array $values, string $field, mixed $value): array
    {
        $value ??= '';
        if ($field === 'accent_color' && is_string($value)) {
            $value = strtolower(trim($value));
        }

        return self::saved($admin, $admin->api->patch('/mini-app', [$field => $value]));
    }

    /**
     * A save's answer: the app — with a popup when the menu button was not set.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    public static function saved(Admin $admin, array $answer): array
    {
        if (is_string($answer['menu_button_error'] ?? null) && $answer['menu_button_error'] !== '') {
            $admin->alert('تنظیمات ذخیره شد، ولی ' . $answer['menu_button_error']);
        }

        return self::app($answer);
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    public static function app(array $answer): array
    {
        return Json::part($answer, 'mini_app');
    }
}
