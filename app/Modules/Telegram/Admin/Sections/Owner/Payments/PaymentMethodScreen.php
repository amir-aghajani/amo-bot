<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Catalog\CatalogFmt;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One payment method (the panel's row and its menu): why nobody is offered it, first; then its card — driver, how it
 * settles, state, the amounts it is offered for, its gateway's word or a card method's automatic approval, payments, its
 * place in checkout —, a card method's cards; «✏️ ویرایش» (PaymentMethodFormScreen, the main action; not for one whose
 * driver is gone) with the switch beside it, a card method's cards, moved up or down the checkout order, and «🗑 حذف» —
 * not the wallet, and not one payments were made with (make it inactive instead, as the panel says), asked first, red.
 *
 * A gateway that takes its webhook address from the shop (`registers_webhook`: Rialit, one address an account) says
 * where its webhooks go, read when the screen opens — a failed read said as unknown, the screen still shown —: the
 * shop's address, none (registered at a tap), another site's (replaced only after a question: that site stops getting
 * them, and Rialit tells its account's owner), or unknown (read again at a tap). What a save or a tap just did is said
 * over it (saved()), nothing when the address was the shop's already.
 *
 * State: `hook` ({id, webhook, say, replaced}: the webhook as a save or a tap just left it, taken once by the next show;
 * `say` while what was done is to be said over it).
 *
 * Actions: `on` (id, 0/1), `up` (id), `down` (id), `del` (id: asks), `gone` (id: deletes), `hook` (id: the shop's
 * address registered), `swap` (id: asks), `swapped` (id: another site's address replaced), `look` (id: read again).
 */
final class PaymentMethodScreen extends BaseScreen
{
    public const ID = 'pmv';

    /** The webhook line's label. */
    private const HOOK = '🔗 وب‌هوک ریالیت';

    /** The button that replaces another site's address. */
    private const SWAP = '🔁 جایگزین کردن با آدرس فروشگاه';

    /**
     * The webhook `$webhook` (the API's GatewayWebhookState, or null for a method that registers none) as a save of
     * method `$id` or a tap just left it: the screen opened next draws it without reading it again, and says what was
     * done over it. `$replaced`: the change asked was another site's address replaced.
     */
    public static function saved(Admin $admin, int $id, mixed $webhook, bool $replaced = false): void
    {
        if (is_array($webhook)) {
            $admin->remember(self::ID, ['hook' => ['id' => $id, 'webhook' => $webhook, 'say' => true, 'replaced' => $replaced]]);
        }
    }

    public function show(Admin $admin, array $args): View
    {
        $methods = Methods::all($admin);
        $index = self::index($methods, self::id($args));
        if ($index === null) {
            return $admin->open(PaymentMethodsScreen::ID);
        }
        $id = (int) ($methods[$index]['id'] ?? 0);
        $kept = $admin->state(self::ID)['hook'] ?? null;
        if ($kept !== null) {
            $admin->remember(self::ID, ['hook' => null]);
        }
        if (!self::registers($methods[$index])) {
            return $this->view($methods, $index, null);
        }
        if (is_array($kept) && (int) ($kept['id'] ?? 0) === $id && is_array($kept['webhook'] ?? null)) {
            $say = Json::on($kept, 'say');

            return $this->view($methods, $index, $kept['webhook'], $say)->notices($say ? self::outcome($kept['webhook'], Json::on($kept, 'replaced')) : []);
        }

        return $this->view($methods, $index, self::read($admin, $id));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        $methods = Methods::all($admin);
        $index = self::index($methods, $id);
        if ($index === null) {
            return $admin->open(PaymentMethodsScreen::ID);
        }
        $method = $methods[$index];
        switch ($action) {
            case 'on':
                $on = self::id($args, 1) === 1;
                $admin->api->patch("/payment-methods/{$id}", ['enabled' => $on]);
                $admin->notice($on ? '✅ فعال شد' : Words::INACTIVE . ' شد');
                break;
            case 'up':
            case 'down':
                $ids = CatalogFmt::moved($methods, $id, $action === 'up' ? -1 : 1, static fn(array $row): bool => true);
                if ($ids !== null) {
                    $admin->api->post('/payment-methods/reorder', ['ids' => $ids]);
                    $admin->notice($action === 'up' ? Words::UP . ' یک جا بالاتر رفت' : Words::DOWN . ' یک جا پایین‌تر رفت');
                }
                break;
            case 'del':
                $payments = (int) ($method['counts']['payments'] ?? 0);
                if ($payments > 0) {
                    $admin->alert('با «' . (string) ($method['label'] ?? '') . '» ' . Fmt::number($payments) . ' پرداخت ثبت شده و برای حفظ تاریخچه حذف نمی‌شود؛ اگر نمی‌خواهید مشتری آن را ببیند، غیرفعالش کنید.');
                    break;
                }

                return $admin->confirm(
                    'این روش پرداخت حذف شود؟',
                    Button::to(Words::yes('حذف شود', CatalogFmt::emoji(Words::DELETE)), self::ID, 'gone', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                    '«' . Fmt::e((string) ($method['label'] ?? '')) . '» حذف می‌شود و دیگر به مشتری نشان داده نمی‌شود.' . "\n" . 'هنوز پرداختی با این روش ثبت نشده است. اگر فقط می‌خواهید موقتا کنار برود، به جای حذف غیرفعالش کنید.',
                    danger: true,
                );
            case 'gone':
                $admin->api->delete("/payment-methods/{$id}");
                $admin->notice('🗑 روش پرداخت حذف شد');

                return $admin->open(PaymentMethodsScreen::ID);
            case 'hook':
            case 'swapped':
                if (self::registers($method)) {
                    $replace = $action === 'swapped';
                    self::saved($admin, $id, $admin->api->post("/payment-methods/{$id}/webhook", ['replace' => $replace])['webhook'] ?? null, $replace);
                }
                break;
            case 'swap':
                if (!self::registers($method)) {
                    break;
                }
                $webhook = self::read($admin, $id);
                $url = self::text($webhook, 'url');
                if (($webhook['state'] ?? null) !== 'elsewhere' || $url === null) {
                    // Not another site's any more: the screen as it stands now, without reading it again.
                    $admin->remember(self::ID, ['hook' => ['id' => $id, 'webhook' => $webhook, 'say' => false]]);
                    break;
                }
                $ours = self::text($webhook, 'ours');

                return $admin->confirm(
                    'آدرس وب‌هوک ریالیت با آدرس فروشگاه جایگزین شود؟',
                    Button::to(Words::yes('جایگزین شود', '🔁'), self::ID, 'swapped', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                    'ریالیت برای هر حساب فقط یک آدرس وب‌هوک دارد و الان خبر پرداخت‌ها را به این آدرس می‌فرستد:' . "\n" . Fmt::code($url) . "\n"
                    . ($ours !== null ? 'از این به بعد به آدرس فروشگاه می‌فرستد:' . "\n" . Fmt::code($ours) . "\n" : '')
                    . "\n" . 'آن آدرس دیگر وب‌هوک‌های ریالیت را نمی‌گیرد؛ اگر سایت یا ربات دیگری با همین حساب ریالیت کار می‌کند، خبر پرداخت‌هایش دیگر به آن نمی‌رسد.' . "\n"
                    . 'ریالیت این تغییر را در تلگرام و ایمیل به صاحب حساب خبر می‌دهد.',
                    danger: true,
                );
            case 'look':
                if (!self::registers($method)) {
                    break;
                }
                $webhook = self::read($admin, $id);
                $admin->remember(self::ID, ['hook' => ['id' => $id, 'webhook' => $webhook, 'say' => false]]);
                if (!in_array($webhook['state'] ?? null, ['ours', 'none', 'elsewhere'], true)) {
                    $admin->notice('⚠️ باز هم خوانده نشد');
                }
                break;
        }

        return $this->show($admin, [(string) $id]);
    }

    /**
     * @param list<array<string, mixed>> $methods
     * @param array<string, mixed>|null $webhook Where its webhooks go; null for a method that registers none
     * @param bool $said Whether the webhook's message is said over the screen already
     */
    private function view(array $methods, int $index, ?array $webhook, bool $said = false): View
    {
        $method = $methods[$index];
        $id = (int) ($method['id'] ?? 0);
        $on = Json::on($method, 'enabled');
        $manual = ($method['driver'] ?? null) === 'manual';
        $cards = Json::rows($method, 'cards');
        $toSupport = Json::on($method, 'to_support');
        $why = PaymentWords::unoffered($method);
        $view = View::make(Icons::METHODS . ' ' . (string) ($method['label'] ?? ''), ...PaymentWords::under());
        if ($why !== null) {
            $view->line('⚠️ ' . Fmt::e($why));
        }
        $view->card([
            'درایور' => Fmt::e((string) ($method['driver_label'] ?? '')),
            'نوع' => Fmt::e(PaymentWords::kind($method)),
            'وضعیت' => Fmt::active($on),
            'مبلغ' => PaymentWords::bounds($method),
            ...PaymentWords::facts($method),
            'پرداخت‌ها' => Fmt::number((int) ($method['counts']['payments'] ?? 0)),
            'جای آن در صفحه پرداخت' => CatalogFmt::place($index + 1, count($methods)),
        ]);
        if ($manual) {
            $view->section(PaymentCardsScreen::TITLE)->lines(...($cards === [] && !$toSupport ? [Words::NONE] : PaymentWords::cards($cards, $toSupport)));
        }
        if ($webhook !== null) {
            $view->line()->lines(...self::hookLines($webhook, $said));
        }

        // The main action first, the switch beside it; the ways about after; the delete last.
        $view->row(
            ($method['kind'] ?? null) !== null ? Button::to(Words::EDIT, PaymentMethodFormScreen::ID, 'edit', $id)->primary() : null,
            $on ? Button::to(Words::DEACTIVATE, self::ID, 'on', $id, 0)->danger() : Button::to(Words::ACTIVATE, self::ID, 'on', $id, 1)->success(),
        );
        if ($manual) {
            $view->row(Button::to(PaymentCardsScreen::TITLE . Fmt::badge(count($cards)), PaymentCardsScreen::ID, 'show', $id));
        }
        if ($webhook !== null) {
            $view->row(match ($webhook['state'] ?? null) {
                'ours' => null,
                'none' => Button::to('🔗 ثبت آدرس در ریالیت', self::ID, 'hook', $id)->success(),
                'elsewhere' => Button::to(self::SWAP, self::ID, 'swap', $id),
                default => Button::to('🔄 دوباره امتحان', self::ID, 'look', $id),
            });
        }
        $view->row(
            $index > 0 ? Button::to(Words::UP . ' بالاتر', self::ID, 'up', $id) : null,
            $index < count($methods) - 1 ? Button::to(Words::DOWN . ' پایین‌تر', self::ID, 'down', $id) : null,
        );
        if (($method['builtin'] ?? false) !== true) {
            $view->row(Button::to(Words::DELETE . ' روش پرداخت', self::ID, 'del', $id)->danger());
        } else {
            $view->note('کیف پول داخلی است و حذف نمی‌شود؛ نامش، مبلغ‌ها و وضعیتش عوض می‌شود.');
        }

        return $view->back(PaymentMethodsScreen::ID);
    }

    /**
     * Whether the method's gateway takes its webhook address from the shop (Rialit).
     *
     * @param array<string, mixed> $method
     */
    private static function registers(array $method): bool
    {
        return Json::on($method, 'registers_webhook');
    }

    /**
     * Where method `$id`'s webhooks go, read from its gateway now: a refusal is an unknown one with the API's words, so
     * the screen still shows.
     *
     * @return array<string, mixed>
     */
    private static function read(Admin $admin, int $id): array
    {
        try {
            $webhook = $admin->api->get("/payment-methods/{$id}/webhook")['webhook'] ?? null;
        } catch (ApiRefusal $refusal) {
            return ['state' => 'unknown', 'url' => null, 'message' => $refusal->lines()];
        }

        return is_array($webhook) ? $webhook : ['state' => 'unknown', 'url' => null, 'message' => null];
    }

    /**
     * The webhook's line (HTML): the shop's address, none, another site's — the address under it, copied at a tap —, or
     * why it is not known; the API's word under it (why a change failed) unless `$said` over the screen already.
     *
     * @param array<string, mixed> $webhook
     * @return list<string>
     */
    private static function hookLines(array $webhook, bool $said): array
    {
        $url = self::text($webhook, 'url');
        $message = self::text($webhook, 'message');
        $lines = match ($webhook['state'] ?? null) {
            'ours' => [self::HOOK . ': 🟢 آدرس فروشگاه ثبت است'],
            'none' => [self::HOOK . ': ' . CatalogFmt::emoji(Words::OFF) . ' ثبت نشده'],
            'elsewhere' => [self::HOOK . ': ⚠️ به آدرس دیگری می‌رود', ...($url === null ? [] : [Fmt::code($url)])],
            default => [self::HOOK . ': ⚠️ ' . Fmt::e($message ?? 'وضعیتش خوانده نشد.')],
        };
        if (!$said && $message !== null && in_array($webhook['state'] ?? null, ['none', 'elsewhere'], true)) {
            $lines[] = '⚠️ ' . Fmt::e($message);
        }

        return $lines;
    }

    /**
     * What a save or a tap did to the webhook (plain text), said over the screen: the address registered, a change that
     * failed and why (the API keeps the state it read and says why), another site's left as it was — the way to replace
     * it —, or why it is not known; nothing when it was the shop's already. `$replaced`: the tap was the replace.
     *
     * @param array<string, mixed> $webhook
     * @return list<string>
     */
    private static function outcome(array $webhook, bool $replaced): array
    {
        $message = self::text($webhook, 'message');

        return match ($webhook['state'] ?? null) {
            'ours' => $message === null ? [] : ['✅ ' . $message],
            'none' => $message === null ? [] : ['⚠️ ' . $message],
            // Another site's left as it was: the API's word (why a replace failed, or that it was not replaced) and —
            // unless the tap was the replace itself — the button that replaces it.
            'elsewhere' => $replaced
                ? ['⚠️ ' . ($message ?? 'وب‌هوک ریالیت هنوز به آدرس دیگری می‌رود.')]
                : ['⚠️ ' . ($message ?? 'وب‌هوک ریالیت به آدرس دیگری می‌رود و فروشگاه آن را عوض نکرد؛ اگر آن آدرس دیگر لازم نیست، جایگزینش کنید.'), 'برای جایگزین کردن، «' . self::SWAP . '» را بزنید.'],
            default => ['⚠️ ' . ($message ?? 'وضعیت وب‌هوک ریالیت خوانده نشد.')],
        };
    }

    /**
     * The webhook's `$key` as text; null for none.
     *
     * @param array<string, mixed> $webhook
     */
    private static function text(array $webhook, string $key): ?string
    {
        return is_string($webhook[$key] ?? null) && $webhook[$key] !== '' ? $webhook[$key] : null;
    }

    /**
     * Where the method `$id` is in checkout order; null for none.
     *
     * @param list<array<string, mixed>> $methods
     */
    private static function index(array $methods, int $id): ?int
    {
        foreach ($methods as $index => $method) {
            if ((int) ($method['id'] ?? 0) === $id) {
                return $index;
            }
        }

        return null;
    }
}
