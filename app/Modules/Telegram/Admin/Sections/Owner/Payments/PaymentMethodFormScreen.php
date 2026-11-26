<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\Sections\Owner\Forms\DescribedForm;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\FormScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * A payment method's form for any driver (the panel's MethodForm): the label customers see, the amounts it is offered
 * for, the driver's own fields drawn from its description (GET /payment-methods/drivers; a secret kept unless typed
 * again or cleared), a new card method's first card, and its switch — a new one walked, an edit on its card —, saved in
 * one call. An online gateway's notes (the address it calls) are said over it; for one that takes its webhook address
 * from the shop (Rialit), what the save did with it is said over the method's screen after (PaymentMethodScreen::saved()).
 *
 * Actions besides the form's: `new` (driver key), `edit` (method id).
 */
final class PaymentMethodFormScreen extends FormScreen
{
    public const ID = 'pmf';

    /** The new card method's first card, in the draft (sent as its `cards`). */
    private const CARD = ['card_number' => 'number', 'card_holder' => 'holder', 'card_bank' => 'bank', 'card_cap' => 'daily_cap'];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function form(Admin $admin, array $state): ?DescribedForm
    {
        $driver = Methods::driver($admin, (string) ($state['driver'] ?? ''));
        if ($driver === null) {
            return null;
        }
        $card = (string) $state['driver'] === 'manual' && self::isNew($state);

        return new DescribedForm(array_values(array_filter([
            DescribedForm::field('label', 'text', 'نام روش', 'روی دکمه پرداخت نوشته می‌شود؛ با چند روش از یک نوع، فرقشان را در نام بیاورید.', required: true, default: (string) ($driver['label'] ?? '')),
            DescribedForm::field('min_amount', 'amount', 'کمترین مبلغ', 'مبلغ‌های کمتر این روش را نمی‌بینند؛ خالی یعنی بدون محدودیت.'),
            DescribedForm::field('max_amount', 'amount', 'بیشترین مبلغ', 'مبلغ‌های بیشتر این روش را نمی‌بینند؛ خالی یعنی بدون محدودیت.'),
            ...Json::rows($driver, 'fields'),
            $card ? DescribedForm::field('card_number', 'card', 'شماره کارت', 'به هر پرداخت یکی از کارت‌های فعال نشان داده می‌شود؛ کارت‌های بیشتر را بعدا از «💳 کارت‌ها» اضافه کنید.', required: true) : null,
            $card ? DescribedForm::field('card_holder', 'text', 'نام صاحب کارت', required: true) : null,
            $card ? DescribedForm::field('card_bank', 'text', 'بانک', 'مشتری آن را زیر شماره کارت می‌بیند، در ربات و سایت؛ مثلا «ملت».') : null,
            $card ? DescribedForm::field('card_cap', 'amount', 'سقف روزانه', 'خالی یعنی بدون سقف.') : null,
            DescribedForm::field('enabled', 'toggle', 'فعال', 'مشتری این روش را هنگام پرداخت می‌بیند.', default: true, walk: false),
        ])), $this->crypt);
    }

    protected function title(array $state): string
    {
        return self::isNew($state) ? UiWords::add((string) ($state['label'] ?? '')) : UiWords::EDIT . ' «' . (string) ($state['label'] ?? '') . '»';
    }

    protected function path(array $state): array
    {
        return PaymentWords::under();
    }

    protected function saveLabel(array $state): string
    {
        return self::isNew($state) ? UiWords::CREATE : UiWords::SAVE;
    }

    protected function leave(Admin $admin, array $state): View
    {
        return self::isNew($state) ? $admin->open(PaymentDriversScreen::ID) : $admin->open(PaymentMethodScreen::ID, 'show', [(int) $state['key']]);
    }

    /** An online gateway's notes (the address it calls, to paste by hand), over the card. */
    protected function intro(View $view, Admin $admin, array $state): void
    {
        if (($state['kind'] ?? null) === 'online') {
            foreach ((array) ($state['notes'] ?? []) as $note) {
                $view->line('ℹ️ ' . Words::note(is_scalar($note) ? (string) $note : ''));
            }
        }
    }

    /**
     * What the driver is and how it is set up (its description), first in the card's folded help; on an edit, what the
     * bounds are — and for a card method, what becomes of a card paid with.
     *
     * @param array<string, mixed> $state
     */
    protected function folded(array $state): string
    {
        return Fmt::e(self::isNew($state)
            ? (string) ($state['description'] ?? '')
            : ((string) $state['driver'] === 'manual'
                ? 'هر پرداخت کارتی را که به آن نشان داده شده نگه می‌دارد. شماره کارتی که با آن پرداخت شده عوض نمی‌شود؛ اگر کارت عوض شده، کارت تازه‌ای اضافه و قبلی را غیرفعال کنید.'
                : 'کمترین و بیشترین مبلغ، مبلغ‌هایی است که این روش برای آن‌ها به مشتری پیشنهاد می‌شود.'));
    }

    protected function action(Admin $admin, string $action, array $args, array $state, DescribedForm $form): View
    {
        if ($action === 'new') {
            $driver = Methods::driver($admin, $args[0] ?? '');
            if ($driver === null || ($driver['traits']['builtin'] ?? false) === true) {
                return $admin->open(PaymentDriversScreen::ID);
            }
            $state = self::about($driver) + ['key' => 0, 'stored' => null];

            return $this->begin($admin, $state, $this->form($admin, $state) ?? $form, walk: true);
        }
        if ($action === 'edit') {
            $method = Methods::find($admin, self::id($args));
            $driver = $method === null ? null : Methods::driver($admin, (string) ($method['driver'] ?? ''));
            if ($method === null || $driver === null) {
                return $admin->open(PaymentMethodsScreen::ID);
            }
            $money = static fn(mixed $amount): ?string => is_numeric($amount) ? (string) (int) round((float) $amount) : null;
            $stored = (Json::part($method, 'config')) + [
                'label' => (string) ($method['label'] ?? ''),
                'min_amount' => $money($method['min_amount'] ?? null),
                'max_amount' => $money($method['max_amount'] ?? null),
                'enabled' => Json::on($method, 'enabled'),
            ];
            $state = ['label' => (string) ($method['label'] ?? '')] + self::about($driver) + ['key' => (int) $method['id'], 'stored' => $stored];

            return $this->begin($admin, $state, $this->form($admin, $state) ?? $form, walk: false);
        }

        return parent::action($admin, $action, $args, $state, $form);
    }

    protected function save(Admin $admin, array $state, DescribedForm $form): View
    {
        $body = $form->payload(self::draftOf($state));
        if ((string) $state['driver'] === 'manual') {
            if (self::isNew($state)) {
                $card = ['enabled' => true];
                foreach (self::CARD as $field => $key) {
                    $card[$key] = $body[$field] ?? '';
                    unset($body[$field]);
                }
                $body['cards'] = [$card];
            } else {
                $body['cards'] = Methods::cards(Methods::find($admin, (int) $state['key']) ?? []);
            }
        }
        if (self::isNew($state)) {
            $answer = $admin->api->post('/payment-methods', $body + ['driver' => (string) $state['driver']]);
            $admin->notice('✅ روش پرداخت اضافه شد');
        } else {
            $answer = $admin->api->put('/payment-methods/' . (int) $state['key'], $body);
            $admin->notice('✅ روش پرداخت ذخیره شد');
        }
        $method = Json::part($answer, 'method');
        $id = (int) ($method['id'] ?? 0);
        $admin->forget(self::ID);
        // A gateway that takes its webhook address from the shop (Rialit) had it registered by the save: what was done
        // is said over the method's screen.
        PaymentMethodScreen::saved($admin, $id, $answer['webhook'] ?? null);

        return $admin->open(PaymentMethodScreen::ID, 'show', [$id]);
    }

    /**
     * What the form says of its driver: its key, label, description, how it settles and its notes.
     *
     * @param array<string, mixed> $driver
     * @return array<string, mixed>
     */
    private static function about(array $driver): array
    {
        return [
            'driver' => (string) ($driver['key'] ?? ''),
            'label' => (string) ($driver['label'] ?? ''),
            'description' => (string) ($driver['description'] ?? ''),
            'kind' => $driver['traits']['kind'] ?? null,
            'notes' => array_values(array_filter((array) ($driver['notes'] ?? []), is_string(...))),
        ];
    }
}
