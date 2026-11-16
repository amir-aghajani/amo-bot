<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\DescribedForm;
use App\Modules\Telegram\Admin\Sections\Owner\Forms\FormScreen;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * A card of a card method added or edited (a row of the panel's CardsEditor): its number, holder, bank, daily cap and
 * switch — a new one walked, an edit on its card; a card payments were shown keeps its number —, saved with the
 * method's other cards in one PUT (Methods::saveCards()). A refusal names the card by its place («کارت ۲: …»).
 *
 * Actions besides the form's: `new` (method id), `edit` (card id).
 */
final class PaymentCardFormScreen extends FormScreen
{
    public const ID = 'pmcn';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function form(Admin $admin, array $state): DescribedForm
    {
        return new DescribedForm(array_values(array_filter([
            Json::on($state, 'paid') ? null : DescribedForm::field('number', 'card', 'شماره کارت', '۱۶ رقم، همان‌طور که روی کارت چاپ شده.', required: true),
            DescribedForm::field('holder', 'text', 'نام صاحب کارت', 'همان‌طور که بانک نشان می‌دهد.', required: true),
            DescribedForm::field('bank', 'text', 'بانک', 'مشتری آن را زیر شماره کارت می‌بیند، در ربات و سایت؛ مثلا «ملت».'),
            DescribedForm::field('daily_cap', 'amount', 'سقف روزانه', 'خالی یعنی بدون سقف.'),
            DescribedForm::field('enabled', 'toggle', 'فعال', 'غیرفعال که باشد، به پرداخت تازه‌ای نشان داده نمی‌شود.', default: true, walk: false),
        ])), $this->crypt);
    }

    protected function title(array $state): string
    {
        return self::isNew($state) ? Words::add('کارت') : Words::EDIT . ' کارت';
    }

    protected function path(array $state): array
    {
        return PaymentWords::under((string) ($state['label'] ?? ''), Fmt::bare(PaymentCardsScreen::TITLE));
    }

    protected function saveLabel(array $state): string
    {
        return self::isNew($state) ? Words::CREATE : Words::SAVE;
    }

    protected function intro(View $view, Admin $admin, array $state): void
    {
        if (Json::on($state, 'paid')) {
            $view->line('شماره کارت: ' . Fmt::code((string) ($state['number'] ?? '')) . ' — با این کارت پرداخت شده؛ شماره‌اش عوض نمی‌شود.');
        }
    }

    protected function leave(Admin $admin, array $state): View
    {
        return self::isNew($state) ? $admin->open(PaymentCardsScreen::ID, 'show', [(int) ($state['method'] ?? 0)]) : $admin->open(PaymentCardScreen::ID, 'show', [(int) $state['key']]);
    }

    protected function action(Admin $admin, string $action, array $args, array $state, DescribedForm $form): View
    {
        if ($action === 'new') {
            $method = Methods::find($admin, self::id($args));
            if ($method === null) {
                return $admin->open(PaymentMethodsScreen::ID);
            }
            $admin->remember(PaymentCardsScreen::ID, ['method' => (int) $method['id']]);
            $state = ['key' => 0, 'method' => (int) $method['id'], 'label' => (string) ($method['label'] ?? ''), 'stored' => null];

            return $this->begin($admin, $state, $this->form($admin, $state), walk: true);
        }
        if ($action === 'edit') {
            $method = Methods::find($admin, (int) ($admin->state(PaymentCardsScreen::ID)['method'] ?? 0));
            foreach ((array) ($method['cards'] ?? []) as $card) {
                if (is_array($card) && (int) ($card['id'] ?? 0) === self::id($args)) {
                    $state = [
                        'key' => (int) $card['id'],
                        'method' => (int) ($method['id'] ?? 0),
                        'label' => (string) ($method['label'] ?? ''),
                        'paid' => (int) ($card['payments'] ?? 0) > 0,
                        'number' => trim(chunk_split((string) ($card['number'] ?? ''), 4, ' ')),
                        'stored' => [
                            'number' => (string) ($card['number'] ?? ''),
                            'holder' => (string) ($card['holder'] ?? ''),
                            'bank' => (string) ($card['bank'] ?? ''),
                            'daily_cap' => is_scalar($card['daily_cap'] ?? null) ? (string) (int) round((float) $card['daily_cap']) : null,
                            'enabled' => Json::on($card, 'enabled'),
                        ],
                    ];

                    return $this->begin($admin, $state, $this->form($admin, $state), walk: false);
                }
            }

            return $admin->open(PaymentCardsScreen::ID);
        }

        return parent::action($admin, $action, $args, $state, $form);
    }

    protected function save(Admin $admin, array $state, DescribedForm $form): View
    {
        $method = Methods::find($admin, (int) ($state['method'] ?? 0)) ?? [];
        $cards = Methods::cards($method);
        $typed = $form->payload(self::draftOf($state));
        if (self::isNew($state)) {
            $cards[] = $typed;
        } else {
            foreach ($cards as $index => $card) {
                if ($card['id'] === (int) $state['key']) {
                    $cards[$index] = $typed + $card;
                }
            }
        }
        Methods::saveCards($admin, $this->crypt, $method, $cards);
        $admin->forget(self::ID);
        $admin->notice(self::isNew($state) ? '✅ کارت اضافه شد' : '✅ کارت ذخیره شد');

        return self::isNew($state) ? $admin->open(PaymentCardsScreen::ID, 'show', [(int) ($method['id'] ?? 0)]) : $admin->open(PaymentCardScreen::ID, 'show', [(int) $state['key']]);
    }
}
