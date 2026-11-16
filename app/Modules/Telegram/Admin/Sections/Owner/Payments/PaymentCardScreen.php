<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Core\Security\Encrypter;
use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Catalog\CatalogFmt;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One card of a card method (a row of the panel's CardsEditor): its number, holder, bank, switch, daily cap and today's
 * take, and the payments shown it; «✏️ ویرایش» (PaymentCardFormScreen), switched on or off, moved up or down, and «🗑
 * حذف» — asked first, and only for a card no payment was shown (one that was keeps its number, made inactive instead).
 * Each change saves the method whole with its cards (Methods::saveCards()).
 *
 * Actions: `on` (card id, 0/1), `up` (card id), `down` (card id), `del` (card id: asks), `gone` (card id: removes).
 */
final class PaymentCardScreen extends BaseScreen
{
    public const ID = 'pmcv';

    public function __construct(private readonly Encrypter $crypt) {}

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        [$method, $index] = $this->find($admin, self::id($args));

        return $method === null || $index === null ? $admin->open(PaymentCardsScreen::ID) : $this->view($method, $index);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        [$method, $index] = $this->find($admin, $id);
        if ($method === null || $index === null) {
            return $admin->open(PaymentCardsScreen::ID);
        }
        $cards = Methods::cards($method);
        switch ($action) {
            case 'on':
                $on = self::id($args, 1) === 1;
                $cards[$index]['enabled'] = $on;
                $method = Methods::saveCards($admin, $this->crypt, $method, $cards);
                $admin->notice($on ? '✅ کارت فعال شد' : CatalogFmt::emoji(Words::INACTIVE) . ' کارت غیرفعال شد');
                break;
            case 'up':
            case 'down':
                $other = $index + ($action === 'up' ? -1 : 1);
                if (isset($cards[$other])) {
                    [$cards[$index], $cards[$other]] = [$cards[$other], $cards[$index]];
                    $method = Methods::saveCards($admin, $this->crypt, $method, $cards);
                    $admin->notice($action === 'up' ? Words::UP . ' یک جا بالاتر رفت' : Words::DOWN . ' یک جا پایین‌تر رفت');
                }
                break;
            case 'del':
                // A card shown to a payment keeps its number (a window from before it was, a button forged): said, not asked.
                if ((int) ($method['cards'][$index]['payments'] ?? 0) > 0) {
                    $admin->alert('با این کارت پرداخت شده و برای حفظ تاریخچه حذف نمی‌شود؛ اگر نمی‌خواهید به مشتری نشان داده شود، غیرفعالش کنید.');
                    break;
                }

                return $admin->confirm(
                    'این کارت حذف شود؟',
                    Button::to(Words::yes('حذف شود', CatalogFmt::emoji(Words::DELETE)), self::ID, 'gone', $id),
                    Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id),
                    PaymentWords::summary((string) ($method['cards'][$index]['summary'] ?? '')) . "\n" . 'هنوز پرداختی به این کارت نشان داده نشده است.',
                    danger: true,
                );
            case 'gone':
                array_splice($cards, $index, 1);
                Methods::saveCards($admin, $this->crypt, $method, array_values($cards));
                $admin->notice('🗑 کارت حذف شد');

                return $admin->open(PaymentCardsScreen::ID);
        }

        [$method, $index] = $this->find($admin, $id, $method);

        return $method === null || $index === null ? $admin->open(PaymentCardsScreen::ID) : $this->view($method, $index);
    }

    /**
     * @param array<string, mixed> $method
     */
    private function view(array $method, int $index): View
    {
        $cards = Json::rows($method, 'cards');
        $card = $cards[$index];
        $id = (int) ($card['id'] ?? 0);
        $on = Json::on($card, 'enabled');
        $paid = (int) ($card['payments'] ?? 0) > 0;
        $number = (string) ($card['number'] ?? '');
        $view = View::make('💳 کارت ' . Fmt::number($index + 1), ...PaymentWords::under((string) ($method['label'] ?? '')));
        if ($on && PaymentWords::full($card) && !Json::on($method, 'to_support')) {
            $view->line('⚠️ سقف روزانه این کارت امروز پر شده؛ تا فردا به پرداخت تازه‌ای نشان داده نمی‌شود.');
        }
        $view->card([
            'شماره کارت' => Fmt::code(trim(chunk_split($number, 4, ' '))),
            'نام صاحب کارت' => Fmt::e((string) ($card['holder'] ?? '')),
            'بانک' => is_string($card['bank'] ?? null) ? Fmt::e($card['bank']) : '',
            'وضعیت' => Fmt::active($on),
            'سقف روزانه' => is_scalar($card['daily_cap'] ?? null) ? Fmt::money((string) $card['daily_cap']) : 'بدون سقف',
            'امروز' => Fmt::money((string) ($card['today'] ?? '0')),
            'پرداخت‌ها' => Fmt::number((int) ($card['payments'] ?? 0)),
            'جای آن' => CatalogFmt::place($index + 1, count($cards)),
        ]);
        if ($paid) {
            $view->note('با این کارت پرداخت شده؛ شماره‌اش عوض و حذف نمی‌شود. اگر کارت عوض شده، کارت تازه‌ای اضافه و این را غیرفعال کنید.');
        }

        $view->row(Button::to(Words::EDIT, PaymentCardFormScreen::ID, 'edit', $id)->primary(), $on ? Button::to(Words::DEACTIVATE, self::ID, 'on', $id, 0)->danger() : Button::to(Words::ACTIVATE, self::ID, 'on', $id, 1)->success())
            ->row(Button::copy(Words::copy('شماره کارت'), $number))
            ->row(
                $index > 0 ? Button::to(Words::UP . ' بالاتر', self::ID, 'up', $id) : null,
                $index < count($cards) - 1 ? Button::to(Words::DOWN . ' پایین‌تر', self::ID, 'down', $id) : null,
            );
        if (!$paid) {
            $view->row(Button::to(Words::DELETE . ' کارت', self::ID, 'del', $id)->danger());
        }

        return $view->back(PaymentCardsScreen::ID, Nav::SHOW, (int) ($method['id'] ?? 0));
    }

    /**
     * The open method (PaymentCardsScreen's) and where card `$id` is among its cards.
     *
     * @param array<string, mixed>|null $method The method as just saved, when it was
     * @return array{0: array<string, mixed>|null, 1: int|null}
     */
    private function find(Admin $admin, int $id, ?array $method = null): array
    {
        $method ??= Methods::find($admin, (int) ($admin->state(PaymentCardsScreen::ID)['method'] ?? 0));
        foreach (array_values(array_filter((array) ($method['cards'] ?? []), is_array(...))) as $index => $card) {
            if ((int) ($card['id'] ?? 0) === $id) {
                return [$method, $index];
            }
        }

        return [$method, null];
    }
}
