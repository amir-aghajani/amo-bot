<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\MirzaBot;

use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Staging\Staged;
use App\Support\Persian;

/**
 * What the owner is told alike of any bot of MirzaBot's line beside its customers (Census): its agents' prices, payments
 * still awaiting approval, its panels — links that went through the bot, 3x-ui on its old login, certificates the bot
 * never checked — and its tickets, which stay behind.
 */
final class Notes
{
    public const SENIOR_PRICES = 'قیمت‌های جدای «نماینده پیشرفته» منتقل نمی‌شود: هر پلن اینجا یک قیمت نمایندگی دارد، همان قیمت «نماینده عادی» و اگر نبود، پیشرفته.';
    private const AWAITING_PAYMENTS = '%s پرداخت در ربات قبلی هنوز منتظر تایید است؛ اول آن‌ها را آنجا تایید کنید و بعد پشتیبان تازه بگیرید، وگرنه این مبلغ‌ها به کیف پول آن مشتری‌ها نمی‌رسد.';
    private const LINKS_THROUGH_BOT = 'لینک اشتراک مشتری‌های پنل %s از دامنه ربات قبلی می‌گذشت؛ با خاموش شدن آن ربات این لینک‌ها کار نمی‌کنند و مشتری‌ها باید لینک تازه را از این ربات بگیرند.';
    private const OLD_THREE_XUI = "پنل %s با روش قدیمی (نام کاربری و رمز) به ربات قبلی وصل بود؛ کانکتور \u{2068}3X-UI\u{2069} این فروشگاه نسخه 3 به بعد را می‌خواهد و سرویس‌های پنل قدیمی‌تر منتقل نمی‌شوند.";
    private const UNCHECKED_TLS = 'ربات قبلی گواهی TLS پنل‌ها را بررسی نمی‌کرد، ولی این فروشگاه بررسی می‌کند: پنلی با گواهی خودامضا یا منقضی به‌عنوان سرور تازه اضافه نمی‌شود؛ آن را در «سرورها» با «بررسی گواهی TLS» خاموش اضافه کنید و همان را برایش انتخاب کنید.';

    /** The payments awaiting an admin's approval, told when there are any. */
    public static function awaiting(int $payments): ?string
    {
        return $payments > 0 ? sprintf(self::AWAITING_PAYMENTS, Persian::number($payments)) : null;
    }

    /**
     * The panels' notes: links through the old bot (of panels with services the import would bring), 3x-ui panels on an
     * admin's login, and — when a panel would be added over https — the certificate check the old bot never made.
     *
     * @param list<string> $throughBot
     * @param list<string> $oldThreeXui
     * @param list<ImportedPanel> $panels
     * @return list<string>
     */
    public static function panels(array $throughBot, array $oldThreeXui, array $panels): array
    {
        $withServices = array_map(static fn(ImportedPanel $panel): string => $panel->key, array_filter($panels, static fn(ImportedPanel $panel): bool => $panel->services > 0));
        $throughBot = array_values(array_intersect($throughBot, $withServices));
        $overHttps = array_filter($panels, static fn(ImportedPanel $panel): bool => $panel->addable() && str_starts_with(strtolower((string) $panel->url), 'https://'));

        return array_values(array_filter([
            $throughBot !== [] ? sprintf(self::LINKS_THROUGH_BOT, self::names($throughBot)) : null,
            $oldThreeXui !== [] ? sprintf(self::OLD_THREE_XUI, self::names($oldThreeXui)) : null,
            $overHttps !== [] ? self::UNCHECKED_TLS : null,
        ]));
    }

    /** Its tickets: its support messages (`support_message`), one thread a tracking code. */
    public static function threads(Staged $staged): int
    {
        $threads = [];
        foreach ($staged->rows('support_message') as $seq => $row) {
            $threads[Cell::text($row['Tracking'] ?? null) ?? "#{$seq}"] = true;
        }

        return count($threads);
    }

    /**
     * Panel names in a note, each set apart: «آلمان»، «Rebecca».
     *
     * @param list<string> $names
     */
    public static function names(array $names): string
    {
        return implode('، ', array_map(static fn(string $name): string => "«\u{2068}{$name}\u{2069}»", $names));
    }
}
