<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Customers\CustomerRowsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\PaymentWords;
use App\Modules\Telegram\Admin\Sections\Support\Words as SupportWords;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One payment (the panel's review dialog, GET /payments/{id}): its receipt — the screen is the picture, its facts the
 * caption; a receipt that is no picture is sent as a file —, or why it has none; its standing and who decided it, its
 * customer, what it paid for and its order, its method and gateway, its moments and notes. What its state allows
 * (`actions`): approve and deliver — a shop's admin only a receipt in review, as the API holds them —, ask the online
 * gateway, reject (with a reason for the customer, or without), cancel (asked first), remind the customer, retry the
 * delivery, and — with the grant — refund to the wallet (asked first). An open card payment while Rikhtam is connected
 * takes «📎 پیوست واریز ریختم»: the deposits Rikhtam holds that matched no payment (GET /payments/{id}/rikhtam, the
 * closest first, kept in the screen's state — a deposit's id is no button's to carry, only a mark of it the kept one must
 * match, so a stale or second tap never attaches another —), one picked, asked first, and attached (POST
 * /payments/{id}/rikhtam), which approves the payment; Rikhtam's words are the notice. Whatever else comes of it — the
 * payment taken another way meanwhile, Rikhtam out of reach — is said over the payment as it now stands.
 * «⏭ رسید بعدی» walks the receipts waiting for review, as the dialog's «بعدی» walks the queue.
 *
 * Actions: `ok`, `ck`, `no` (asks how) → `nog` | `non` (asks the reason), `cx` (asks) → `cxg` | `cxn`, `rm`, `rt`,
 * `rf` (asks) → `rfg` | `rfn`, `fl` (the receipt as a file), `nx` (the next receipt), `bg` (Rikhtam's deposits, asked
 * afresh) → `bgl` (n: their page n) → `bgp` (n, mark: deposit n, asks) → `bgy` (n, mark: attached) — each with the
 * payment's id.
 *
 * State: `rikhtam` (`id`: the payment, `deposits`: as Rikhtam listed them); the customer's list it was opened from
 * (CustomerRowsScreen::opened()), where «⬅️ بازگشت» goes.
 */
final class PaymentScreen extends BaseScreen
{
    public const ID = 'pyv';

    /** What approving brings, by what the payment paid for: how the button says it, and the word once done. */
    private const BRINGS = [
        'purchase' => ['تحویل', 'تحویل داده شد'],
        'renewal' => ['تحویل', 'تحویل داده شد'],
        'wallet_topup' => ['شارژ کیف پول', 'کیف پول شارژ شد'],
        'traffic' => ['افزودن حجم', 'حجم به ربات نماینده اضافه شد'],
        'extra_traffic' => ['افزودن حجم', 'حجم به سرویس اضافه شد'],
        'extra_time' => ['افزودن زمان', 'زمان به سرویس اضافه شد'],
        'extra_devices' => ['افزودن کاربر', 'کاربر به سرویس اضافه شد'],
    ];

    /** «⚡️ تایید خودکار واریز» (the owner's payment methods), where Rikhtam's key is sent again. */
    private const DEPOSIT_SETTINGS = 'pmd';

    /** The panel's words for a cancellation, asked first. */
    private const CANCELLING = 'این پرداخت لغو می‌شود؛ سفارشش هم لغو می‌شود و مشتری خبردار می‌شود، مگر رسید پرداخت دیگری از همین سفارش در انتظار بررسی باشد.';

    /** What rejecting does, as the panel's bulk dismissal says it of each receipt. */
    private const REJECTING = 'رسید رد می‌شود: پرداختش ناموفق می‌شود و سفارشش باز می‌ماند تا مشتری دوباره بپردازد یا خودش منقضی شود. مشتری در تلگرام یا با ایمیل خبردار می‌شود.';

    /** A refund's words where it takes back what the order gave a service. */
    private const TAKES_BACK = 'مبلغ به کیف پول مشتری برمی‌گردد و %s پس گرفته می‌شود. اگر پنل جواب ندهد یا سرویس غیرفعال باشد، بازپرداخت انجام نمی‌شود.';

    public function show(Admin $admin, array $args): View
    {
        CustomerRowsScreen::opened($admin, self::ID, self::id($args), $args);

        return $this->view($admin, self::payment($admin, self::id($args)));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        switch ($action) {
            case 'ok':
                $payment = self::row($admin->api->post("/payments/{$id}/approve"));
                $this->settled($admin, $payment, 'تایید شد ولی تحویل انجام نشد', '✅ پرداخت ' . Fmt::id($id) . ' تایید شد و ' . self::brings($payment)[1]);

                return $this->view($admin, $payment);
            case 'ck':
                $answer = $admin->api->post("/payments/{$id}/check");
                $payment = self::row($answer);
                $this->checked($admin, $payment, (string) ($answer['state'] ?? ''));

                return $this->view($admin, $payment);
            case 'no':
                return Sales::choose(
                    'رسید پرداخت ' . Fmt::id($id) . ' رد شود؟',
                    self::REJECTING,
                    Button::to(Sales::WITH_NOTE, self::ID, 'non', $id),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'nog', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                );
            case 'nog':
                return $this->decided($admin, $id, 'reject', null, '🚫 پرداخت ' . Fmt::id($id) . ' رد شد');
            case 'non':
                return $admin->ask(new Prompt('دلیل رد کردن', self::ID, 'non', [$id], 'مثلا: مبلغ رسید با سفارش نمی‌خواند — مشتری این متن را می‌بیند. ' . Sales::NOTE_HINT));
            case 'cx':
                return Sales::choose(
                    'پرداخت ' . Fmt::id($id) . ' لغو شود؟',
                    self::CANCELLING,
                    Button::to(Sales::WITH_NOTE, self::ID, 'cxn', $id),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'cxg', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                );
            case 'cxg':
                return $this->decided($admin, $id, 'cancel', null, '🚫 پرداخت ' . Fmt::id($id) . ' لغو شد');
            case 'cxn':
                return $admin->ask(new Prompt('توضیح برای مشتری', self::ID, 'cxn', [$id], 'مثلا: سفارش تکراری بود. ' . Sales::NOTE_HINT));
            case 'rm':
                $answer = $admin->api->post("/payments/{$id}/remind");
                $payment = self::row($answer);
                $this->reminded($admin, $payment, (string) ($answer['delivery'] ?? ''));

                return $this->view($admin, $payment);
            case 'rt':
                $payment = self::row($admin->api->post("/payments/{$id}/retry"));
                $this->settled($admin, $payment, 'باز هم تحویل نشد', '✅ پرداخت ' . Fmt::id($id) . ' تحویل دوباره انجام شد');

                return $this->view($admin, $payment);
            case 'rf':
                $payment = self::payment($admin, $id);

                return Sales::choose(
                    'پرداخت ' . Fmt::id($id) . ' بازپرداخت شود؟',
                    self::refunding($payment),
                    Button::to(Sales::WITH_NOTE, self::ID, 'rfn', $id),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'rfg', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                );
            case 'rfg':
                return $this->refunded($admin, $id, null);
            case 'rfn':
                return $admin->ask(new Prompt('توضیح بازپرداخت', self::ID, 'rfn', [$id], 'اختیاری؛ در تراکنش کیف پول مشتری ثبت می‌شود — حداکثر ۱۹۰ نویسه.'));
            case 'fl':
                if ($admin->send($admin->api->file("/payments/{$id}/receipt"), 'رسید پرداخت ' . Fmt::id($id))) {
                    $admin->notice('📎 فایل رسید فرستاده شد');
                }

                return $this->show($admin, $args);
            case 'nx':
                $next = self::next($admin, $id);
                if ($next === null) {
                    $admin->notice('رسید دیگری در انتظار بررسی نیست');

                    return $admin->open(PaymentsScreen::ID);
                }

                return $this->show($admin, [(string) $next]);
            case 'bg':
                return $this->deposits($admin, $id, 1, true);
            case 'bgl':
                return $this->deposits($admin, $id, self::page($args, 1), false);
            case 'bgp':
                return $this->attaching($admin, $id, self::id($args, 1), $args[2] ?? '');
            case 'bgy':
                return $this->attach($admin, $id, self::id($args, 1), $args[2] ?? '');
        }

        return $this->show($admin, $args);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);

        return match ($action) {
            'non' => $this->decided($admin, $id, 'reject', $answer->text(), '🚫 پرداخت ' . Fmt::id($id) . ' رد شد'),
            'cxn' => $this->decided($admin, $id, 'cancel', $answer->text(), '🚫 پرداخت ' . Fmt::id($id) . ' لغو شد'),
            'rfn' => $this->refunded($admin, $id, $answer->text()),
            default => $this->show($admin, $args),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function payment(Admin $admin, int $id): array
    {
        return self::row($admin->api->get("/payments/{$id}"));
    }

    /**
     * The payment an answer carries.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private static function row(array $answer): array
    {
        return Json::part($answer, 'payment');
    }

    /**
     * What approving the payment brings: [how the button says it, the word once done].
     *
     * @param array<string, mixed> $payment
     * @return array{0: string, 1: string}
     */
    private static function brings(array $payment): array
    {
        return self::BRINGS[(string) (Json::part($payment, 'order')['type'] ?? '')] ?? ['تحویل', 'تحویل داده شد'];
    }

    /**
     * Rikhtam's deposits that matched no payment, to attach one to payment `$id`: asked of Rikhtam afresh (`$fresh`) and
     * kept, or read from what was kept for this payment (another page); each a button, the exact sum marked «🎯». A payment
     * that may take none any more (paid or closed meanwhile, or Rikhtam refusing the shop's key) is shown as it stands,
     * with why; Rikhtam out of reach is said in a popup, the screen left as it was.
     *
     * @throws ApiRefusal
     */
    private function deposits(Admin $admin, int $id, int $page, bool $fresh): View
    {
        $kept = (array) ($admin->state(self::ID)['rikhtam'] ?? []);
        if ($fresh || (int) ($kept['id'] ?? 0) !== $id) {
            try {
                $answer = $admin->api->get("/payments/{$id}/rikhtam");
            } catch (ApiRefusal $refusal) {
                if ($refusal->status !== 422 && $refusal->status !== 403) {
                    throw $refusal;
                }

                return $this->moved($admin, $id, $refusal->lines());
            }
            $kept = ['id' => $id, 'connected' => Json::on($answer, 'connected'), 'deposits' => Json::rows($answer, 'deposits')];
            $admin->remember(self::ID, ['rikhtam' => $kept]);
        }
        $payment = self::payment($admin, $id);
        $amount = (string) ($payment['amount'] ?? '0');
        $view = View::make('📎 پیوست واریز ریختم', 'مدیریت', 'فروش', 'پرداخت ' . Fmt::id($id));

        if (($kept['connected'] ?? false) !== true) {
            $settings = $admin->opens(self::DEPOSIT_SETTINGS);
            $view->line($settings
                ? 'ریختم دیگر به فروشگاه وصل نیست؛ کلید امنیتی درگاه را در «روش‌های پرداخت» › «⚡️ تایید خودکار واریز» دوباره بفرستید.'
                : 'ریختم دیگر به فروشگاه وصل نیست؛ تا مالک فروشگاه کلید امنیتی درگاه را دوباره در «⚡️ تایید خودکار واریز» نفرستد، واریزی پیوست نمی‌شود.');
            if ($settings) {
                $view->row(Button::to('⚡️ تایید خودکار واریز', self::DEPOSIT_SETTINGS));
            }

            return $view->back(self::ID, 'show', $id);
        }

        $view->line('مشتری‌ای که مبلغ را گرد کرده خودکار تایید نشده است: واریزش را از میان واریزهایی که ریختم به پرداختی وصل نکرده انتخاب کنید تا این پرداخت با آن تایید شود.')
            ->line()
            ->line('• مبلغ پرداخت: <b>' . Fmt::money($amount) . '</b>');
        $deposits = Json::rows($kept, 'deposits');
        if ($deposits === []) {
            $view->line()->line('📭 ریختم واریزی ندارد که به پرداختی وصل نشده باشد. اگر مشتری همین الان واریز کرده، کمی بعد دوباره ببینید.');
        } else {
            $view->note('نزدیک‌ترین مبلغ اول؛ 🎯 یعنی درست همین مبلغ، و ⚠️ یعنی کمتر از مبلغ خود سفارش، که پرداخت را تایید نمی‌کند.');
            $choices = [];
            foreach ($deposits as $n => $deposit) {
                $choices[] = Button::to(self::depositLabel($deposit, $amount), self::ID, 'bgp', $id, $n, self::mark($deposit));
            }
            $view->paged(1, $choices, $page, static fn(int $at): array => [self::ID, 'bgl', [$id, $at]]);
        }

        return $view->row(Button::to('🔄 تازه‌سازی', self::ID, 'bg', $id))->back(self::ID, 'show', $id);
    }

    /**
     * Deposit `$n` of the kept list (`$mark` its mark), asked before it is attached to payment `$id`: what it is — how far
     * from the payment's sum —, and what attaching it does.
     *
     * @throws ApiRefusal
     */
    private function attaching(Admin $admin, int $id, int $n, string $mark): View
    {
        $deposit = self::keptDeposit($admin, $id, $n, $mark);
        if ($deposit === null) {
            return $this->relisted($admin, $id);
        }
        if (($deposit['covers'] ?? true) === false) {
            // Short of the order's own price: attaching it would be refused (Deposits::attach()), so it is not offered.
            $admin->notice('⚠️ این واریز از مبلغ خود سفارش کمتر است و پرداخت را تایید نمی‌کند؛ اگر مشتری باقی را هم واریز کرده، آن واریز را انتخاب کنید.');

            return $this->deposits($admin, $id, intdiv($n, View::CHOICES_MOST) + 1, false);
        }
        $payment = self::payment($admin, $id);
        $sum = (string) (Json::text($deposit, 'amount') ?? '0');
        $price = (string) (Json::text($payment, 'amount') ?? '0');
        $account = Json::text($deposit, 'account');
        $received = Json::text($deposit, 'received_at');
        $where = array_filter([$account !== null ? 'به حساب ' . Fmt::ltr('····' . $account) : null, $received !== null ? Fmt::date($received, true) : null]);

        return $admin->confirm(
            'واریز ریختم به پرداخت ' . Fmt::id($id) . ' پیوست شود؟',
            Button::to('✔️ بله، پیوست و تایید', self::ID, 'bgy', $id, $n, self::mark($deposit)),
            Button::to('✖️ انصراف', self::ID, 'bgl', $id, intdiv($n, View::CHOICES_MOST) + 1),
            implode("\n", array_filter([
                '• واریز: <b>' . Fmt::money($sum) . '</b>' . self::distance($sum, $price),
                $where === [] ? null : '• ' . implode(' · ', $where),
                '• پرداخت ' . Fmt::id($id) . ': ' . Fmt::money($price),
                '',
                'پرداخت با این واریز تایید می‌شود و ' . self::brings($payment)[0] . ' انجام می‌شود. ریختم کارمزد معمولش را برای این واریز می‌گیرد.',
            ], static fn(?string $line): bool => $line !== null)),
        );
    }

    /**
     * Deposit `$n` (`$mark` its mark) attached to payment `$id` — the tap answered at once, since Rikhtam calls the shop
     * back in the middle of it, and what came of it said over the screen after: approved — Rikhtam's fee and words with
     * it —, or the delivery failed, said with why. Rikhtam's refusal (the deposit taken meanwhile, short of the order's
     * price) is said and its deposits asked again; anything else — Rikhtam out of reach (it may have attached it all the
     * same), the payment taken another way meanwhile — over the payment as it now stands.
     *
     * @throws ApiRefusal
     */
    private function attach(Admin $admin, int $id, int $n, string $mark): View
    {
        $deposit = self::keptDeposit($admin, $id, $n, $mark);
        if ($deposit === null) {
            return $this->relisted($admin, $id);
        }
        $admin->notice('⏳ واریز در ریختم پیوست می‌شود…');
        try {
            $answer = $admin->api->post("/payments/{$id}/rikhtam", ['uid' => (string) ($deposit['uid'] ?? '')]);
        } catch (ApiRefusal $refusal) {
            if ($refusal->status !== 422 || $refusal->field('uid') === null) {
                return $this->moved($admin, $id, $refusal->lines());
            }
            $admin->alert($refusal->lines());
            try {
                return $this->deposits($admin, $id, 1, true);
            } catch (ApiRefusal $again) {
                return $this->moved($admin, $id, $again->lines());
            }
        }
        $admin->remember(self::ID, ['rikhtam' => null]);
        $payment = self::row($answer);
        $fee = Json::text($answer, 'fee');
        $said = array_values(array_filter([$fee !== null ? 'کارمزد ریختم: ' . Fmt::money($fee) : null, Json::text($answer, 'message')]));
        $said = implode('', array_map(static fn(string $part): string => '. ' . rtrim($part, '. '), $said));
        if (($payment['status'] ?? null) !== 'paid') {
            // Rikhtam took the attach, but its call back has not paid the payment (yet): never said done.
            $admin->alert('ریختم واریز را پیوست کرد ولی پرداخت ' . Fmt::id($id) . ' هنوز تایید نشده است' . $said . '. کمی بعد «🔄 تازه‌سازی» را بزنید؛ اگر باز تایید نشده بود، واریز را در پنل ریختم ببینید.');

            return $this->view($admin, $payment);
        }
        $this->settled($admin, $payment, 'تایید شد ولی تحویل انجام نشد', '✅ پرداخت ' . Fmt::id($id) . ' با واریز ریختم تایید شد و ' . self::brings($payment)[1] . $said);

        return $this->view($admin, $payment);
    }

    /**
     * A deposit picked from a list that is no longer the one kept (a stale or second tap): the deposits asked afresh, the
     * change said when another list was kept for the payment.
     *
     * @throws ApiRefusal
     */
    private function relisted(Admin $admin, int $id): View
    {
        if ((int) (((array) ($admin->state(self::ID)['rikhtam'] ?? []))['id'] ?? 0) === $id) {
            $admin->notice('🔄 فهرست واریزهای ریختم عوض شده بود؛ دوباره انتخاب کنید.');
        }

        return $this->deposits($admin, $id, 1, true);
    }

    /**
     * Payment `$id` after attaching came to something else (`$why`): said, the kept deposits forgotten, and the payment as
     * it now stands.
     *
     * @throws ApiRefusal
     */
    private function moved(Admin $admin, int $id, string $why): View
    {
        $admin->remember(self::ID, ['rikhtam' => null]);
        $admin->alert($why);

        return $this->view($admin, self::payment($admin, $id));
    }

    /**
     * Deposit `$n` of what was kept for payment `$id`, while it is still the one marked `$mark`; null when nothing is kept
     * for it (another payment's, or gone) or another deposit is there now.
     *
     * @return array<string, mixed>|null
     */
    private static function keptDeposit(Admin $admin, int $id, int $n, string $mark): ?array
    {
        $kept = (array) ($admin->state(self::ID)['rikhtam'] ?? []);
        $deposit = (int) ($kept['id'] ?? 0) === $id ? ((array) ($kept['deposits'] ?? []))[$n] ?? null : null;

        return is_array($deposit) && is_string($deposit['uid'] ?? null) && $deposit['uid'] !== '' && hash_equals(self::mark($deposit), $mark) ? $deposit : null;
    }

    /**
     * What a deposit's buttons carry of it beside its place in the list: a mark of its id (Telegram carries 64 bytes of a
     * button's data, and Rikhtam's ids are longer), which the kept deposit must still match.
     *
     * @param array<string, mixed> $deposit
     */
    private static function mark(array $deposit): string
    {
        return hash('crc32b', (string) ($deposit['uid'] ?? ''));
    }

    /**
     * How far a deposit's sum is from the payment's, after it (HTML): «درست همین مبلغ», or how much less or more.
     */
    private static function distance(string $sum, string $price): string
    {
        if (!is_numeric($sum) || !is_numeric($price)) {
            return '';
        }
        $gap = bcsub($sum, $price, 2);

        return match (bccomp($gap, '0', 2)) {
            0 => ' — درست همان مبلغ پرداخت',
            -1 => ' — ' . Fmt::money(ltrim($gap, '-')) . ' کمتر از مبلغ پرداخت',
            default => ' — ' . Fmt::money($gap) . ' بیشتر از مبلغ پرداخت',
        };
    }

    /**
     * A deposit as its button says it: its sum — «🎯» before it when it is the payment's very sum, «⚠️» when it is short
     * of the order's own price and pays nothing —, the account it came to (left to right) and how long ago.
     *
     * @param array<string, mixed> $deposit
     */
    private static function depositLabel(array $deposit, string $amount): string
    {
        $sum = (string) (Json::text($deposit, 'amount') ?? '0');
        $account = Json::text($deposit, 'account');
        $received = Json::text($deposit, 'received_at');

        return implode(' · ', array_filter([
            match (true) {
                ($deposit['covers'] ?? true) === false => '⚠️ ',
                is_numeric($sum) && is_numeric($amount) && bccomp($sum, $amount, 2) === 0 => '🎯 ',
                default => '',
            } . Fmt::money($sum),
            $account !== null ? "\u{2068}····{$account}\u{2069}" : null,
            $received !== null ? SupportWords::ago($received) : null,
        ]));
    }

    /** A decision with a note for the customer, or none (reject, cancel): the payment as the panel then has it. */
    private function decided(Admin $admin, int $id, string $decision, ?string $note, string $done): View
    {
        $payment = self::row($admin->api->post("/payments/{$id}/{$decision}", $note === null ? null : ['note' => $note]));
        $admin->notice($done);

        return $this->view($admin, $payment);
    }

    private function refunded(Admin $admin, int $id, ?string $note): View
    {
        $payment = self::row($admin->api->post("/payments/{$id}/refund", $note === null ? null : ['note' => $note]));
        $admin->notice('↩️ پرداخت ' . Fmt::id($id) . ((Json::part($payment, 'order')['type'] ?? '') === 'wallet_topup' ? ' بازپرداخت شد' : ' به کیف پول بازپرداخت شد'));

        return $this->view($admin, $payment);
    }

    /**
     * Said after an approval or a retry: delivered — or the delivery failed, said with why (the retry is right there).
     *
     * @param array<string, mixed> $payment
     */
    private function settled(Admin $admin, array $payment, string $failed, string $done): void
    {
        $order = Json::part($payment, 'order');
        if (($order['status'] ?? '') === 'failed') {
            $admin->alert('پرداخت ' . Fmt::id((int) ($payment['id'] ?? 0)) . ' ' . $failed . '. ' . (Json::text($order, 'notes') ?? ''));

            return;
        }
        $admin->notice($done);
    }

    /**
     * What the gateway said when asked: paid — into its order, or, the order closed meanwhile, into the wallet —, still
     * waiting, or its page closed.
     *
     * @param array<string, mixed> $payment
     */
    private function checked(Admin $admin, array $payment, string $state): void
    {
        $number = 'پرداخت ' . Fmt::id((int) ($payment['id'] ?? 0)) . ' ';
        $order = Json::part($payment, 'order');
        if ($state === 'paid' && !Json::on($payment, 'to_wallet') && ($order['status'] ?? '') === 'failed') {
            $admin->alert($number . 'تایید شد ولی تحویل انجام نشد. ' . (Json::text($order, 'notes') ?? ''));

            return;
        }
        $admin->notice(match ($state) {
            'paid' => '✅ ' . $number . (Json::on($payment, 'to_wallet') ? 'در درگاه پرداخت شده بود؛ سفارشش بسته بود و مبلغ به کیف پول مشتری رفت' : 'در درگاه پرداخت شده بود؛ تایید شد و ' . self::brings($payment)[1]),
            'waiting' => 'ℹ️ ' . $number . 'هنوز در درگاه پرداخت نشده است',
            default => '⚠️ ' . $number . 'در درگاه پرداخت نشد و مهلت صفحه پرداخت تمام شده است',
        });
    }

    /**
     * Whether the reminder reached the customer — in Telegram, by email —, and why not when it did not.
     *
     * @param array<string, mixed> $payment
     */
    private function reminded(Admin $admin, array $payment, string $delivery): void
    {
        $telegram = Json::text(Json::part($payment, 'user'), 'telegram_id') !== null;
        $why = match ($delivery) {
            'told' => null,
            'emailed' => null,
            'turned_away' => 'مشتری ربات را مسدود کرده یا حسابش دیگر نیست',
            'unreachable' => $telegram ? 'تلگرام در دسترس نبود' : 'سرور ایمیل در دسترس نبود',
            'refused' => 'تلگرام پیام را نپذیرفت',
            default => 'این مشتری تلگرام ندارد، و ایمیلی هم برایش نرفت: ایمیلی ندارد، یا ارسال ایمیل راه نیفتاده است',
        };
        if ($why !== null) {
            $admin->alert('یادآوری فرستاده نشد: ' . $why . '.');

            return;
        }
        $admin->notice('🔔 پرداخت ' . Fmt::id((int) ($payment['id'] ?? 0)) . ($delivery === 'emailed' ? ' یادآوری با ایمیل فرستاده شد' : ' یادآوری فرستاده شد'));
    }

    /**
     * What a refund does for what the payment paid for, as the panel's confirmation says it.
     *
     * @param array<string, mixed> $payment
     */
    private static function refunding(array $payment): string
    {
        return match ((string) (Json::part($payment, 'order')['type'] ?? '')) {
            'renewal' => sprintf(self::TAKES_BACK, 'زمان و حجمی که این تمدید به سرویس داده'),
            'traffic' => 'مبلغ به کیف پول نماینده برمی‌گردد و حجمی که با آن خریده بود از حجم رباتش کم می‌شود؛ اگر از آن حجم فروخته باشد، بازپرداخت انجام نمی‌شود.',
            'extra_traffic' => sprintf(self::TAKES_BACK, 'حجمی که به سرویس اضافه شده'),
            'extra_time' => sprintf(self::TAKES_BACK, 'زمانی که به سرویس اضافه شده'),
            'extra_devices' => sprintf(self::TAKES_BACK, 'کاربرهایی که به سرویس اضافه شده'),
            'wallet_topup' => 'مبلغ را خودتان بیرون از فروشگاه به مشتری برگردانید؛ همین مبلغ از کیف پولش کم می‌شود. اگر خرجش کرده باشد، بازپرداخت انجام نمی‌شود.',
            default => 'مبلغ به کیف پول مشتری برمی‌گردد و سرویسی که با این خرید ساخته شده از پنل و فروشگاه حذف می‌شود. اگر پنل جواب ندهد یا سفارش دیگری (تمدید، حجم، زمان یا کاربر اضافه) روی سرویس باشد، بازپرداخت انجام نمی‌شود.',
        };
    }

    /**
     * The receipt waiting for review after payment `$id` in the queue's order (newest first) — the first, once `$id` is
     * no longer in it (decided) —; null when none other waits.
     */
    private static function next(Admin $admin, int $id): ?int
    {
        $first = null;
        $found = false;
        for ($page = 1; $page <= 4; $page++) {
            $answer = $admin->api->get('/payments', ['status' => PaymentsScreen::REVIEW, 'page' => $page]);
            foreach (Json::rows($answer, 'payments') as $row) {
                $rowId = (int) ($row['id'] ?? 0);
                if ($found) {
                    return $rowId;
                }
                $found = $rowId === $id;
                $first ??= $rowId === $id ? null : $rowId;
            }
            if ($page >= (int) (Json::part($answer, 'meta')['last_page'] ?? 1)) {
                break;
            }
        }

        return $found ? null : $first;
    }

    /**
     * Why a payment has no receipt, as the panel says it.
     *
     * @param array<string, mixed> $payment
     */
    private static function noReceipt(array $payment): string
    {
        return match (true) {
            ($payment['gateway'] ?? '') === 'outside' => 'پرداخت خارج از فروشگاه که پشتیبانی ثبت کرده؛ رسیدی در کار نیست',
            ($payment['kind'] ?? '') === 'instant' => 'پرداخت از کیف پول؛ رسیدی در کار نیست',
            ($payment['kind'] ?? '') === 'online' => 'پرداخت آنلاین در صفحه درگاه؛ رسیدی در کار نیست و نتیجه‌اش را درگاه می‌گوید',
            ($payment['status'] ?? '') === 'pending' => 'مشتری هنوز رسیدی نفرستاده است',
            default => 'این پرداخت رسیدی ندارد',
        };
    }

    /** @param array<string, mixed> $payment */
    private function view(Admin $admin, array $payment): View
    {
        $id = (int) ($payment['id'] ?? 0);
        $status = (string) ($payment['status'] ?? '');
        $order = Json::part($payment, 'order');
        $receipt = Json::part($payment, 'receipt');
        $held = Json::on($payment, 'held');
        $online = ($payment['kind'] ?? '') === 'online';
        $reviewer = Json::text($payment, 'reviewer');
        $brings = Sales::brings($order);
        $description = Json::text($payment, 'description');
        $expires = Json::text($payment, 'link_expires_at');

        $view = View::make('💳 پرداخت ' . Fmt::id($id), 'مدیریت', 'فروش', 'پرداخت‌ها')->facts([
            'وضعیت' => Fmt::e(Sales::word(Sales::PAYMENT_STATUS, $status))
                . (Json::on($payment, 'auto_approved') ? ' · خودکار، بعد از مهلت بررسی' : '')
                . ($held ? ' · ' . Sales::HELD : '')
                . ($reviewer !== null && !Json::on($payment, 'auto_approved') ? ' · ' . Sales::reviewer($reviewer, Json::text($payment, 'reviewer_name')) : ''),
            'مبلغ' => '<b>' . Fmt::money(Json::text($payment, 'amount')) . '</b>',
            'تخفیف' => Sales::discounts($order),
        ])->facts(Sales::customerFacts(Json::part($payment, 'user')))->facts([
            'بابت' => Fmt::e(implode(' · ', array_filter([Sales::word(Sales::ORDER_TYPE, $order['type'] ?? ''), $brings[1] ?? null, Json::text($order, 'plan'), Json::text($order, 'server')]))),
            'سفارش' => Fmt::id((int) ($order['id'] ?? 0)) . ' · ' . Fmt::e(Sales::word(Sales::ORDER_STATUS, $order['status'] ?? '')),
            'خطای تحویل' => ($order['status'] ?? '') === 'failed' && Json::text($order, 'notes') !== null ? Fmt::e((string) Json::text($order, 'notes')) : null,
            'روش' => Fmt::e((string) ($payment['method'] ?? '')) . (Json::text($payment, 'summary') !== null ? ' · ' . PaymentWords::summary((string) Json::text($payment, 'summary')) : '')
                . ($online && Json::text($payment, 'gateway_label') !== null ? ' · درگاه ' . Fmt::e((string) Json::text($payment, 'gateway_label')) : ''),
            ($online ? 'شناسه در درگاه' : 'مرجع') => Json::text($payment, 'reference') !== null ? Fmt::code(Json::text($payment, 'reference')) : null,
            'مهلت پرداخت در درگاه' => $status === 'pending' && !$held && $expires !== null ? Fmt::date($expires, true) . ' (' . Sales::left($expires) . ')' : null,
            'آخرین استعلام' => Sales::when($payment, 'checked_at'),
            'ثبت' => Sales::when($payment, 'created_at'),
            'رسید' => Sales::when($receipt, 'sent_at'),
            'پرداخت' => Sales::when($payment, 'paid_at'),
            'پیام مشتری' => Json::text($receipt, 'note') !== null ? '<blockquote>' . Fmt::e(Fmt::cut(Json::text($receipt, 'note'), 300)) . '</blockquote>' : null,
            'بررسی پشتیبانی' => $held && $description !== null ? Fmt::e($description) : null,
            'دلیل' => !$held && in_array($status, ['failed', 'cancelled'], true) && $description !== null ? Fmt::e($description) : null,
            'کیف پول' => Json::on($payment, 'to_wallet') && $description !== null ? Fmt::e($description) : null,
            'توضیح بازپرداخت' => Json::text($payment, 'refund_note') !== null ? Fmt::e((string) Json::text($payment, 'refund_note')) : null,
        ]);

        $file = false;
        if ($receipt === []) {
            $view->line('🧾 ' . self::noReceipt($payment));
        } else {
            try {
                $picture = $admin->api->file("/payments/{$id}/receipt");
                if ($picture->isImage()) {
                    $view->picture($picture);
                } else {
                    $file = true;
                    $view->line('🧾 رسید فایلی است که به صورت عکس نشان داده نمی‌شود؛ «📎 فایل رسید» آن را می‌فرستد.');
                }
            } catch (ApiRefusal $refusal) {
                $view->line('🧾 ' . Fmt::e($refusal->getMessage()));
            }
        }

        $actions = Json::part($payment, 'actions');
        $does = self::brings($payment)[0];
        // A shop's admin approves a receipt in review alone (ActorRefusedException::RECEIPT_FIRST): no manual approval.
        $approves = Json::on($actions, 'approve') && ($status === PaymentsScreen::REVIEW || !$admin->seat->isStaff());
        $view->row(
            $approves ? Button::to('✅ ' . ($status === PaymentsScreen::REVIEW ? 'تایید' : 'تایید دستی') . ' و ' . $does, self::ID, 'ok', $id) : null,
            Json::on($actions, 'reject') ? Button::to('🚫 رد کردن', self::ID, 'no', $id) : null,
        );
        // Attaching a Rikhtam deposit approves the payment: refused to a shop's admin exactly where approving is.
        if (Json::on($actions, 'rikhtam') && ($status === PaymentsScreen::REVIEW || !$admin->seat->isStaff())) {
            $view->row(Button::to('📎 پیوست واریز ریختم', self::ID, 'bg', $id));
        }
        $view->row(
            Json::on($actions, 'check') ? Button::to('🔎 استعلام از درگاه', self::ID, 'ck', $id) : null,
            Json::on($actions, 'retry') ? Button::to('🔁 تلاش دوباره برای تحویل', self::ID, 'rt', $id) : null,
        );
        $view->row(
            Json::on($actions, 'remind') ? Button::to('🔔 یادآوری به مشتری', self::ID, 'rm', $id) : null,
            Json::on($actions, 'cancel') ? Button::to('🚫 لغو پرداخت', self::ID, 'cx', $id) : null,
        );
        if (Json::on($actions, 'refund') && $admin->seat->holds(StaffGrant::Refunds)) {
            $view->row(Button::to(($order['type'] ?? '') === 'wallet_topup' ? '↩️ بازپرداخت شارژ' : '↩️ بازپرداخت به کیف پول', self::ID, 'rf', $id));
        }
        if ($file) {
            $view->row(Button::to('📎 فایل رسید', self::ID, 'fl', $id));
        }
        $service = is_numeric($order['subscription_id'] ?? null) ? (int) $order['subscription_id'] : 0;
        $view->row(
            Button::to('🛒 سفارش ' . Fmt::id((int) ($order['id'] ?? 0)), OrderScreen::ID, 'show', (int) ($order['id'] ?? 0)),
            $service > 0 ? Button::to('📡 اشتراک ' . Fmt::id($service), SubscriptionScreen::ID, 'show', $service) : null,
        );
        $view->row(...Sales::customerButtons(Json::part($payment, 'user')));
        // The queue walked a receipt after another: from a receipt in review, or the review tab's list.
        $tab = (array) ($admin->state(PaymentsScreen::ID)['f'] ?? []);
        $reviewing = $status === PaymentsScreen::REVIEW || ($tab['status'] ?? null) === PaymentsScreen::REVIEW;
        $view->row(
            $reviewing ? Button::to('⏭ رسید بعدی', self::ID, 'nx', $id) : null,
            Button::to('🔄 تازه‌سازی', self::ID, 'show', $id),
        );

        return $view->back(CustomerRowsScreen::back($admin, self::ID, $id, PaymentsScreen::ID));
    }
}
