<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Panel\Website;

use App\Modules\Telegram\Admin\Sections\Panel\Card;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Settings\SettingsSource;

/** A section of «🌐 تنظیمات وب‌سایت» (the owner's and an agent's): a card over the website's own fields (WebsiteSource). */
abstract class WebsiteCard extends Card
{
    protected function path(): array
    {
        return WebsiteScreen::under();
    }

    protected function parent(): string
    {
        return WebsiteScreen::ID;
    }

    protected function source(): SettingsSource
    {
        return new WebsiteSource();
    }

    /**
     * The website's part `$key` (`telegram`, `email`…) as the API answered it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected static function part(array $values, string $key): array
    {
        $website = Json::part($values, 'website');

        return is_array($website[$key] ?? null) ? $website[$key] : [];
    }
}
