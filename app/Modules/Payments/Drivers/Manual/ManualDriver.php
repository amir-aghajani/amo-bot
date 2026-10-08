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
use App\Modules\Payments\DTO\CardTransfer;
use App\Modules\Payments\Enums\GatewayKind;
use App\Support\BankCard;

/**
 * Card-to-card: the customer pays to a card of the shop's and sends the receipt — in the bot or from the website —,
 * which a person accepts, or the method's review window does for one sent in the bot. A method a card: its form is the
 * card's number, whose card it is, a note for the customer under it and the review window, each kept in the row's
 * config under its name; its gateway is ManualGateway.
 */
final class ManualDriver implements GatewayDriver
{
    private const HOLDER_MAX = 100;
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
            description: 'مشتری مبلغ را به کارت شما واریز می‌کند و رسید را در ربات یا وب‌سایت می‌فرستد؛ شما رسید را بررسی و تایید می‌کنید. برای هر کارت یک روش بسازید.',
            form: new Form($this->key(), [
                new CardNumber(
                    'card_number',
                    'card_number',
                    label: 'شماره کارت',
                    spec: new FieldSpec('شماره کارت', hint: '16 رقم؛ فاصله یا خط تیره مهم نیست.', placeholder: '6037 9977 0000 1119', ltr: true),
                ),
                new Text(
                    'card_holder',
                    'card_holder',
                    '',
                    label: 'نام صاحب کارت',
                    max: self::HOLDER_MAX,
                    required: true,
                    spec: new FieldSpec('نام صاحب کارت', hint: 'همان‌طور که بانک نشان می‌دهد، تا مشتری بداند به چه کسی واریز می‌کند.', placeholder: 'نام و نام خانوادگی'),
                ),
                new Text(
                    'instructions',
                    'instructions',
                    '',
                    label: 'توضیحات',
                    max: self::INSTRUCTIONS_MAX,
                    type: FieldType::Textarea,
                    spec: new FieldSpec('توضیحات برای مشتری', hint: 'زیر شماره کارت در ربات نمایش داده می‌شود؛ مثلا «بعد از واریز، عکس رسید را همین‌جا بفرستید.»'),
                ),
                new ReviewWindow(new FieldSpec(
                    'تایید خودکار',
                    hint: 'خالی یا 0 یعنی فقط تایید دستی. با عددی مثل 30، رسیدی که در ربات فرستاده شود و در این مدت بررسی نشود خودکار تایید و سفارشش تحویل می‌شود؛ مشتری فقط می‌شنود «حداکثر تا ۳۰ دقیقه دیگر». رسیدی که از وب‌سایت بارگذاری شود همیشه منتظر تایید شماست.',
                    placeholder: '0',
                )),
            ]),
            notes: ['بدون کارمزد و بدون نیاز به درگاه', 'هر رسید باید تایید شود تا سفارش تحویل داده شود؛ با دست، یا رسیدی که در ربات فرستاده شود خودکار بعد از مهلتی که تعیین می‌کنید'],
            traits: [self::KIND => GatewayKind::Manual->value, self::BUILTIN => false],
        );
    }

    /** "6037 •••• •••• 1119 · AmoBot" — the card the customer is told to pay to, and whose it is. */
    public function summary(array $settings): ?string
    {
        $card = self::text($settings, 'card_number');
        if ($card === '') {
            return null;
        }
        $holder = self::text($settings, 'card_holder');

        return $holder === '' ? BankCard::mask($card) : BankCard::mask($card) . ' · ' . $holder;
    }

    public function gateway(array $settings): GatewayInterface
    {
        return new ManualGateway(new CardTransfer(self::text($settings, 'card_number'), self::text($settings, 'card_holder'), self::text($settings, 'instructions')));
    }

    /** @param array<string, mixed> $settings */
    private static function text(array $settings, string $name): string
    {
        return is_string($settings[$name] ?? null) ? $settings[$name] : '';
    }
}
