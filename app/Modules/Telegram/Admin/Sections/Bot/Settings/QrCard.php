<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Settings;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\Settings\FieldDef;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «کد QR» (`qr`): whether a delivered service comes with a QR code of its link, and the picture it is drawn on — seen (the
 * picture sent under the card), replaced by a picture sent here (applied at once), or put back to the shipped one, asked
 * first.
 *
 * Actions besides the card's: `see`, `up` (asks for the picture), `back` (asks), `gone` (the shipped one again).
 */
final class QrCard extends BotCard
{
    public const ID = 'bsq';

    public const TITLE = "🔳 کد \u{2068}QR\u{2069}";

    protected function group(): string
    {
        return 'qr';
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function intro(): string
    {
        return "همراه لینک اتصال، یک تصویر کد \u{2068}QR\u{2069} هم برای مشتری فرستاده شود یا نه.";
    }

    protected function fields(): array
    {
        return [
            FieldDef::toggle('qr_enabled', "ساخت کد \u{2068}QR\u{2069}", "روشن که باشد، سرویس تحویل‌شده، سرویس جابه‌جاشده، لینک تازه و «🔗 لینک اشتراک» با تصویر کد \u{2068}QR\u{2069} لینک اتصال روی پس‌زمینه پایین فرستاده می‌شوند و متن سرویس زیر تصویر می‌آید؛ مشتری با اسکن آن، سرویس را به برنامه اضافه می‌کند."),
        ];
    }

    protected function notes(): array
    {
        return ["پس‌زمینه کد \u{2068}QR\u{2069}: تصویری که کد \u{2068}QR\u{2069} روی آن کشیده می‌شود. مربع سفید وسط جای کد است؛ تصویر مربعی (مثلا ۱۰۲۴×۱۰۲۴) بهترین نتیجه را می‌دهد."];
    }

    protected function extra(View $view, Admin $admin, array $values): void
    {
        $background = (array) ($admin->api->get('/bot/settings')['qr_background'] ?? []);
        $custom = Json::on($background, 'custom');
        $view->section("🖼 پس‌زمینه کد \u{2068}QR\u{2069}")
            ->card([
                'تصویر' => $custom ? 'آپلودشده' : 'پیش‌فرض فروشگاه',
                'اندازه' => "\u{2068}" . Fmt::digits((int) ($background['width'] ?? 0)) . '×' . Fmt::digits((int) ($background['height'] ?? 0)) . "\u{2069} · " . Fmt::bytes((int) ($background['size'] ?? 0)),
            ])
            ->row(Button::to('👁 دیدن تصویر', self::ID, 'see'), Button::to('📤 آپلود تصویر جدید', self::ID, 'up'))
            ->row($custom ? Button::to('↩️ بازگشت به تصویر پیش‌فرض', self::ID, 'back')->danger() : null);
    }

    protected function action(Admin $admin, string $action, array $args, array $values): View
    {
        switch ($action) {
            case 'see':
                $admin->send($admin->api->file('/bot/qr-background'), "پس‌زمینه فعلی کد \u{2068}QR\u{2069}");

                return $this->card($admin, $values);
            case 'up':
                $background = (array) ($admin->api->get('/bot/settings')['qr_background'] ?? []);

                return $admin->ask((new Prompt('تصویر پس‌زمینه تازه', self::ID, 'up', [], 'عکس، یا فایل تصویر برای کیفیت بهتر؛ همان لحظه جایگزین می‌شود.', Button::to(Words::CANCEL, self::ID, self::VIEW), file: true))
                    ->limits("\u{2068}JPG\u{2069}، \u{2068}PNG\u{2069} یا \u{2068}WebP\u{2069}، حداکثر " . Fmt::plain(Fmt::bytes((int) ($background['max_bytes'] ?? 0)))));
            case 'back':
                return $admin->confirm(
                    'بازگشت به پس‌زمینه پیش‌فرض؟',
                    Button::to(Words::yes('تصویر پیش‌فرض برگردد'), self::ID, 'gone'),
                    Button::to(Words::CANCEL, self::ID, self::VIEW),
                    "تصویر آپلودشده حذف می‌شود و کدهای \u{2068}QR\u{2069} دوباره روی تصویر پیش‌فرض فروشگاه کشیده می‌شوند. این کار برگشت‌پذیر نیست؛ برای استفاده دوباره از همین تصویر باید آن را دوباره آپلود کنید.",
                    danger: true,
                );
            case 'gone':
                $admin->api->delete('/bot/qr-background');
                $admin->notice('✅ پس‌زمینه به تصویر پیش‌فرض برگشت');

                return $this->card($admin, $values);
        }

        return parent::action($admin, $action, $args, $values);
    }

    protected function typed(Admin $admin, string $action, array $args, Answer $answer, array $values): View
    {
        if ($action !== 'up') {
            return $this->card($admin, $values);
        }
        $picture = $answer->picture() ?? $answer->document();
        if ($picture === null) {
            throw new ApiRefusal(422, 'تصویری نرسید؛ یک عکس یا فایل تصویر بفرستید.');
        }
        $admin->api->upload('/bot/qr-background', 'file', $picture['name'], $picture['bytes'], $picture['mime']);
        $admin->notice("✅ پس‌زمینه کد \u{2068}QR\u{2069} عوض شد");

        return $this->card($admin, $this->source()->load($admin));
    }
}
