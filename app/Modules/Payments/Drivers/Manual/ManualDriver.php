<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Manual;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\FieldType;
use App\Core\Forms\Form;
use App\Modules\Payments\Contracts\GatewayDriver;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\Enums\GatewayKind;

/**
 * Card-to-card: the customer pays to one of the shop's cards and sends a receipt, which support accepts — or the method's
 * review window, for a receipt sent in the bot. The cards are rows of their own (PaymentCard, kept by the method's form
 * as `cards`, Services\PaymentCards): each payment is shown one at random among those switched on with room left today.
 * Its settings: a note for the customer and the window.
 */
final class ManualDriver implements GatewayDriver
{
    private const INSTRUCTIONS_MAX = 1000;

    public function key(): string
    {
        return ManualGateway::key();
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'کارت به کارت',
            description: 'مشتری مبلغ را به یکی از کارت‌های شما واریز می‌کند و رسید را در ربات یا وب‌سایت می‌فرستد؛ شما رسید را بررسی و تایید می‌کنید. به هر پرداخت یکی از کارت‌های روشن این روش به تصادف نشان داده می‌شود.',
            form: new Form($this->key(), [
                new Text(
                    'instructions',
                    'instructions',
                    '',
                    label: 'توضیحات',
                    max: self::INSTRUCTIONS_MAX,
                    type: FieldType::Textarea,
                    spec: new FieldSpec('توضیحات برای مشتری', hint: 'در ربات و سایت زیر شماره کارت نمایش داده می‌شود، و وقتی پرداخت کارت به کارت به پیوی پشتیبانی می‌رود زیر پیام آن؛ مثلا «لطفا فقط از کارت به نام خودتان واریز کنید.»'),
                ),
                new ReviewWindow(new FieldSpec(
                    'تایید خودکار',
                    hint: 'خالی یا 0 یعنی فقط تایید دستی. با عددی مثل 30، رسیدی که در ربات فرستاده شود و در این مدت بررسی نشود خودکار تایید و سفارشش تحویل می‌شود؛ مشتری فقط می‌شنود «حداکثر تا ۳۰ دقیقه دیگر». رسیدی که از وب‌سایت بارگذاری شود همیشه منتظر تایید شماست.',
                    placeholder: '0',
                )),
            ]),
            notes: [
                'بدون کارمزد و بدون نیاز به درگاه',
                'هر رسید باید تایید شود تا سفارش تحویل داده شود؛ با دست، یا رسیدی که در ربات فرستاده شود خودکار بعد از مهلتی که تعیین می‌کنید',
                'چند کارت در یک روش، هر کدام با سقف روزانه دلخواه؛ هر پرداخت یکی را به تصادف نشان می‌دهد',
            ],
            traits: [self::KIND => GatewayKind::Manual->value, self::BUILTIN => false],
        );
    }

    /** None: its cards are rows of their own — the method's row lists them, and each payment names the one it was shown. */
    public function summary(array $settings): ?string
    {
        return null;
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new ManualGateway(is_string($settings['instructions'] ?? null) ? $settings['instructions'] : '');
    }
}
