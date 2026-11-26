<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Cashback;

use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Settings\BotGroupSource;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «💸 کش‌بک» (the panel's «کش‌بک»): whether the program runs — off, a tap turns it on through its card
 * (CashbackSettingsScreen, «⚙️ تنظیمات») — and its rules: a percent for some kinds of order, a customer group and a way
 * to pay (a list the panel answers whole), each a button with its chip, opening its own screen (CashbackRuleScreen);
 * «➕ افزودن قانون کش‌بک» (CashbackRuleForm) and the panel's ⓘ, folded.
 *
 * Actions: ListScreen's; `info` (an older window's «❔ راهنما»: the list, its help folded in it).
 */
final class CashbackRulesScreen extends ListScreen
{
    public const ID = 'cb';

    public const TITLE = Icons::CASHBACK . ' ' . Marketing::CASHBACK;

    /** The program in a paragraph: the panel's ⓘ. */
    private const PROGRAM = 'درصدی از مبلغ هر سفارش تحویل‌شده که به کیف پول مشتری برمی‌گردد — برای نوع سفارش، گروه مشتری و روش پرداختی که قانون می‌گوید. از میان قانون‌های فعالی که با یک سفارش جور هستند بالاترین درصد حساب می‌شود و درصد کش‌بک سطح وفاداری مشتری به آن اضافه می‌شود. هر سفارش یک بار کش‌بک می‌گیرد؛ سفارش رایگان هیچ. اگر سفارش برگشت داده شود، کش‌بکش از مبلغ برگشتی کم می‌شود.';

    /** Whether the program runs, as summary() read it for extra(). */
    private bool $on = false;

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return Marketing::PATH;
    }

    protected function endpoint(): string
    {
        return '/cashback/rules';
    }

    protected function key(): string
    {
        return 'rules';
    }

    protected function searchable(): bool
    {
        return false;
    }

    protected function noun(): string
    {
        return 'قانون';
    }

    protected function empty(): string
    {
        return 'هنوز قانونی ندارید. مثلا ۵٪ برای همه خریدها و تمدیدها، یا ۱۰٪ برای گروه ' . Marketing::iso('VIP') . ' که با کارت به کارت پرداخت می‌کند. بدون قانون، فقط کش‌بک سطح‌های وفاداری داده می‌شود.';
    }

    protected function summary(array $meta): array
    {
        $this->on = $this->here !== null && (bool) (BotGroupSource::settings($this->here)['cashback_enabled'] ?? false);

        return ['کش‌بک: ' . Fmt::onOff($this->on) . ($this->on ? '' : ' · هیچ سفارشی کش‌بک نمی‌گیرد')];
    }

    protected function parent(): string
    {
        return Marketing::MENU;
    }

    protected function item(array $row): Button
    {
        return Button::to(Marketing::dot((bool) ($row['enabled'] ?? false)) . ' ' . self::name($row) . ' · ' . self::group($row) . ' · ' . self::method($row), CashbackRuleScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->row(Button::to(Words::add('قانون کش‌بک'), CashbackRuleForm::ID, 'new')->success())
            ->row(...CashbackSettingsScreen::buttons($this->on, Marketing::CASHBACK))
            ->help(Fmt::e(self::PROGRAM));
    }

    /**
     * A rule's name in a sentence (the panel's ruleName): its percent and its kinds, «۵٪ خرید و تمدید».
     *
     * @param array<string, mixed> $rule
     */
    public static function name(array $rule): string
    {
        $kinds = Json::part($rule, 'order_types');

        return Fmt::percent((int) ($rule['percent'] ?? 0)) . ' ' . implode(' و ', array_map(static fn(mixed $kind): string => Marketing::KINDS[is_string($kind) ? $kind : ''] ?? '', $kinds));
    }

    /** @param array<string, mixed> $rule */
    public static function group(array $rule): string
    {
        return is_array($rule['customer_group'] ?? null) ? (string) ($rule['customer_group']['name'] ?? '') : 'همه مشتری‌ها';
    }

    /** @param array<string, mixed> $rule */
    public static function method(array $rule): string
    {
        return is_array($rule['payment_method'] ?? null) ? (string) ($rule['payment_method']['label'] ?? '') : 'همه روش‌ها';
    }
}
