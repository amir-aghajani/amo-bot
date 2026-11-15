<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Sections\Bot\ReportGroupScreen;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Reports\Topic;

/**
 * «بخش‌های گزارش» (`reports`, under «گروه گزارش‌ها»): a switch per topic of the report group, each with the panel's title
 * and what it reports; the agency's only in the main shop (an agent's group has none, nor its switch).
 */
final class ReportTopicsCard extends BotCard
{
    public const ID = 'rgt';

    protected function group(): string
    {
        return 'reports';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🔔 بخش‌های گزارش';
    }

    protected function path(): array
    {
        return ['مدیریت', 'ربات', 'گروه گزارش‌ها'];
    }

    protected function parent(): string
    {
        return ReportGroupScreen::ID;
    }

    /** Back to the group's card where it was left: opened from «🤖 تنظیمات ربات», it still goes back there. */
    protected function parentAction(): string
    {
        return ReportGroupScreen::VIEW;
    }

    protected function intro(): string
    {
        return 'هر بخش یک تاپیک در گروه گزارش‌ها است. بخشی که خاموش باشد گزارشی نمی‌فرستد؛ تاپیکش در گروه می‌ماند.';
    }

    protected function fields(): array
    {
        return array_map(static function (Topic $topic): FieldDef {
            $field = FieldDef::toggle('report_' . $topic->value, $topic->title(), $topic->about());

            // A switch the shop has not (the agency's, in an agent's shop) is not among the values: not shown.
            return $field->when('report_' . $topic->value, [true, false]);
        }, Topic::cases());
    }
}
