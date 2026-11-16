<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Customers\CustomerRowsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\DepositSettingsScreen;
use App\Modules\Telegram\Admin\Sections\Owner\Payments\PaymentWords;
use App\Modules\Telegram\Admin\Sections\Support\Words as SupportWords;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One payment (the panel's review dialog, GET /payments/{id}): its receipt — the screen is the picture, its summary card
 * the caption; a receipt that is no picture is sent as a file —, or why it has none; its standing and who decided it, its
 * customer, what it paid for, its method and gateway, its moments and notes; its order and service a button each. What
 * its state allows (`actions`): approve and deliver — a shop's admin only a receipt in review, as the API holds them —,
 * ask the online gateway, reject (with a reason for the customer, or without), cancel (asked first), remind the
 * customer, retry the delivery, and — with the grant — refund to the wallet (asked first). An open card payment while
 * Rikhtam is connected takes «📎 پیوست واریز ریختم»: the deposits Rikhtam holds that matched no payment (GET
 * /payments/{id}/rikhtam, the closest first, kept in the screen's state — a deposit's id is no button's to carry, only a
 * mark of it the kept one must match, so a stale or second tap never attaches another —), one picked, asked first, and
 * attached (POST /payments/{id}/rikhtam), which approves the payment; Rikhtam's words are the notice. Whatever else comes
 * of it — the payment taken another way meanwhile, Rikhtam out of reach — is said over the payment as it now stands.
 *
 * The review queue (a receipt in review opened from the shop's payments, not a customer's): «✅ تایید و …، بعدی» approves
 * and opens the next receipt waiting, a rejection moves on the same way, «⏭ رسید بعدی» skips — as the dialog's «بعدی»
 * walks the queue; a delivery that failed stays on the payment, its retry right there. An approval judged on a receipt in
 * review is made only while it still is (stillInReview()): an old window's never approves one decided meanwhile.
 *
 * Actions: `ok` (`r` after the id: judged on a receipt in review), `okn` (approve, then the next receipt), `ck`, `no`
 * (asks how) → `nog` | `non` (asks the reason) — each `q` after the id in the queue, to move on after —, `cx` (asks) →
 * `cxg` | `cxn`, `rm`, `rt`, `rf` (asks) → `rfg` | `rfn`, `fl` (the receipt as a file), `nx` (the next receipt; 0: the
 * first), `bg` (Rikhtam's deposits, asked afresh) → `bgl` (n: their page n) → `bgp` (n, mark: deposit n, asks) → `bgy`
 * (n, mark: attached) — each with the payment's id.
 *
 * State: `rikhtam` (`id`: the payment, `deposits`: as Rikhtam listed them); `walk` (the receipt the queue walked to
 * last); the customer's list it was opened from (CustomerRowsScreen::opened()), where «⬅️ بازگشت» goes.
 */
final class PaymentScreen extends BaseScreen
{
    public const ID = 'pyv';

    /** A decision's argument after the payment's id: made in the review queue, the next receipt follows it. */
    private const QUEUE = 'q';

    /** An approval's argument after the payment's id: judged on a receipt in review, made only while it still is. */
    private const RECEIPT = 'r';

    /** The screen's state of the receipt the queue walked to last (onwards()). */
    private const WALK = 'walk';

    /** The longest note or reason the card carries (a caption holds 1000 characters, the receipt's facts beside it). */
    private const NOTE_MOST = 200;

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

    /** Said of a payment approved whose delivery failed (its order's notes say why). */
    private const UNDELIVERED = 'تایید شد ولی تحویل انجام نشد';

    /** The panel's words for a cancellation, asked first. */
    private const CANCELLING = 'این پرداخت لغو می‌شود؛ سفارشش هم لغو می‌شود و مشتری خبردار می‌شود، مگر رسید پرداخت دیگری از همین سفارش در انتظار بررسی باشد.';

    /** What rejecting does, as the panel's bulk dismissal says it of each receipt. */
    private const REJECTING = 'پرداخت ناموفق می‌شود و سفارشش باز می‌ماند تا مشتری دوباره بپردازد یا خودش منقضی شود. مشتری در تلگرام یا با ایمیل خبردار می‌شود.';

    /** A refund's words where it takes back what the order gave a service. */
    private const TAKES_BACK = 'مبلغ به کیف پول مشتری برمی‌گردد و %s پس گرفته می‌شود. اگر پنل جواب ندهد یا سرویس غیرفعال باشد، بازپرداخت انجام نمی‌شود.';

    public function show(Admin $admin, array $args): View
    {
        CustomerRowsScreen::opened($admin, self::ID, self::id($args), $args);
        // Opened from a customer's list: out of the queue's walk.
        foreach (array_slice($args, 1) as $arg) {
            if (str_starts_with($arg, 'from.')) {
                $admin->remember(self::ID, [self::WALK => null]);
            }
        }

        return $this->view($admin, self::payment($admin, self::id($args)));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        $queue = ($args[1] ?? '') === self::QUEUE;
        switch ($action) {
            case 'ok':
                if (($args[1] ?? '') === self::RECEIPT) {
                    self::stillInReview($admin, $id);
                }
                $payment = self::row($admin->api->post("/payments/{$id}/approve"));
                $this->approved($admin, $id, $payment);

                return $this->view($admin, $payment);
            case 'okn':
                self::stillInReview($admin, $id);
                // The receipt after it, found while it is still in the queue: one skipped before stays behind.
                $after = self::next($admin, $id);
                $payment = self::row($admin->api->post("/payments/{$id}/approve"));
                if (!$this->approved($admin, $id, $payment)) {
                    return $this->view($admin, $payment);
                }

                return $this->onwards($admin, $id, $after);
            case 'ck':
                $answer = $admin->api->post("/payments/{$id}/check");
                $payment = self::row($answer);
                $this->checked($admin, $payment, (string) ($answer['state'] ?? ''));

                return $this->view($admin, $payment);
            case 'no':
                $more = $queue ? [self::QUEUE] : [];

                return Sales::choose(
                    $admin,
                    'رسید پرداخت ' . Fmt::id($id) . ' رد شود؟',
                    Fmt::e(self::REJECTING) . ($queue ? "\n<i>بعد از رد، رسید بعدی باز می‌شود.</i>" : ''),
                    true,
                    Button::to(Sales::WITH_NOTE, self::ID, 'non', $id, ...$more),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'nog', $id, ...$more),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                );
            case 'nog':
                return $this->decided($admin, $id, 'reject', null, Sales::REJECT . ' پرداخت ' . Fmt::id($id) . ' رد شد', $queue);
            case 'non':
                return $admin->ask((new Prompt('دلیل رد کردن', self::ID, 'non', $queue ? [$id, self::QUEUE] : [$id], 'مشتری این متن را می‌بیند.', Button::to(Words::CANCEL, self::ID, 'show', $id)))
                    ->example('مبلغ رسید با سفارش نمی‌خواند')
                    ->limits(Sales::NOTE_LIMIT));
            case 'cx':
                return Sales::choose(
                    $admin,
                    'پرداخت ' . Fmt::id($id) . ' لغو شود؟',
                    Fmt::e(self::CANCELLING),
                    true,
                    Button::to(Sales::WITH_NOTE, self::ID, 'cxn', $id),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'cxg', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                );
            case 'cxg':
                return $this->decided($admin, $id, 'cancel', null, Sales::CANCEL . ' پرداخت ' . Fmt::id($id) . ' لغو شد');
            case 'cxn':
                return $admin->ask((new Prompt('توضیح برای مشتری', self::ID, 'cxn', [$id], 'مشتری این متن را با خبر لغو می‌بیند.'))
                    ->example('سفارش تکراری بود')
                    ->limits(Sales::NOTE_LIMIT));
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
                    $admin,
                    'پرداخت ' . Fmt::id($id) . ' بازپرداخت شود؟',
                    Fmt::e(self::refunding($payment)),
                    true,
                    Button::to(Sales::WITH_NOTE, self::ID, 'rfn', $id),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'rfg', $id),
                    Button::to(Words::CANCEL, self::ID, 'show', $id),
                );
            case 'rfg':
                return $this->refunded($admin, $id, null);
            case 'rfn':
                return $admin->ask((new Prompt('توضیح بازپرداخت', self::ID, 'rfn', [$id], 'در تراکنش کیف پول مشتری ثبت می‌شود.'))
                    ->example('سرویس وصل نشد')
                    ->limits('اختیاری؛ حداکثر ۱۹۰ نویسه'));
            case 'fl':
                if ($admin->send($admin->api->file("/payments/{$id}/receipt"), 'رسید پرداخت ' . Fmt::id($id))) {
                    $admin->notice(Sales::FILE . ' فایل رسید فرستاده شد');
                }

                return $this->show($admin, $args);
            case 'nx':
                return $this->onwards($admin, $id);
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
            'non' => $this->decided($admin, $id, 'reject', $answer->text(), Sales::REJECT . ' پرداخت ' . Fmt::id($id) . ' رد شد', ($args[1] ?? '') === self::QUEUE),
            'cxn' => $this->decided($admin, $id, 'cancel', $answer->text(), Sales::CANCEL . ' پرداخت ' . Fmt::id($id) . ' لغو شد'),
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
     * On through the queue: `$after` (the receipt that followed payment `$id`, found before it was decided), else the one
     * after `$id` in it — the first other one when `$id` is the last or no longer in it (decided, or 0), so receipts
     * skipped come round again at the end —; none left, the payments list with it said. The receipt walked to is the
     * queue's (`walk`), whatever list it was once opened from.
     *
     * @throws ApiRefusal
     */
    private function onwards(Admin $admin, int $id, ?int $after = null): View
    {
        $next = $after ?? self::next($admin, $id);
        if ($next === null) {
            $admin->notice('رسید دیگری در انتظار بررسی نیست');

            return $admin->open(PaymentsScreen::ID);
        }
        $admin->remember(self::ID, [self::WALK => $next]);

        return $this->view($admin, self::payment($admin, $next));
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
        $view = View::make(Sales::ATTACH . ' پیوست واریز ریختم', ...Sales::under(Sales::PAYMENTS, 'پرداخت ' . Fmt::id($id)));

        if (($kept['connected'] ?? false) !== true) {
            // «⚡️ تایید خودکار واریز» (the owner's payment methods), where Rikhtam's key is sent again.
            $settings = $admin->opens(DepositSettingsScreen::ID);
            $view->line($settings
                ? '⚠️ ریختم دیگر به فروشگاه وصل نیست؛ کلید امنیتی درگاه را در «' . Fmt::e(DepositSettingsScreen::TITLE) . '» دوباره بفرستید.'
                : '⚠️ ریختم دیگر به فروشگاه وصل نیست؛ تا مالک فروشگاه کلید امنیتی درگاه را دوباره در «' . Fmt::e(DepositSettingsScreen::TITLE) . '» نفرستد، واریزی پیوست نمی‌شود.');
            if ($settings) {
                $view->row(Button::to(DepositSettingsScreen::TITLE, DepositSettingsScreen::ID)->primary());
            }

            return $view->back(self::ID, 'show', $id);
        }

        $deposits = Json::rows($kept, 'deposits');
        $view->card(['مبلغ پرداخت' => Fmt::money($amount), 'واریزهای بدون پرداخت' => Fmt::number(count($deposits))]);
        if ($deposits === []) {
            $view->line()->line('📭 ریختم واریزی ندارد که به پرداختی وصل نشده باشد. اگر مشتری همین الان واریز کرده، کمی بعد «' . Fmt::e(Words::REFRESH) . '» را بزنید.');
        } else {
            $choices = [];
            foreach ($deposits as $n => $deposit) {
                $choices[] = Button::to(self::depositLabel($deposit, $amount), self::ID, 'bgp', $id, $n, self::mark($deposit));
            }
            $view->paged(1, $choices, $page, static fn(int $at): array => [self::ID, 'bgl', [$id, $at]]);
        }

        return $view->help('مشتری‌ای که مبلغ را گرد کرده خودکار تایید نشده است: واریزش را از میان واریزهایی که ریختم به پرداختی وصل نکرده انتخاب کنید تا این پرداخت با آن تایید شود. نزدیک‌ترین مبلغ اول است؛ 🎯 یعنی درست همین مبلغ، و ⚠️ یعنی کمتر از مبلغ خود سفارش، که پرداخت را تایید نمی‌کند.')
            ->back(self::ID, 'show', $id)
            ->refresh(self::ID, 'bg', $id);
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
        $card = Sales::quote([
            'واریز' => '<b>' . Fmt::money($sum) . '</b>' . self::distance($sum, $price),
            'به حساب' => $account !== null ? Fmt::ltr('····' . $account) : null,
            'زمان واریز' => $received !== null ? Fmt::date($received, true) : null,
            'پرداخت ' . Fmt::id($id) => Fmt::money($price),
        ]);

        return $admin->confirm(
            'واریز ریختم به پرداخت ' . Fmt::id($id) . ' پیوست شود؟',
            Button::to(Words::yes('پیوست و تایید'), self::ID, 'bgy', $id, $n, self::mark($deposit)),
            Button::to(Words::CANCEL, self::ID, 'bgl', $id, intdiv($n, View::CHOICES_MOST) + 1),
            $card . "\n" . 'پرداخت با این واریز تایید می‌شود و ' . self::brings($payment)[0] . ' انجام می‌شود. ریختم کارمزد معمولش را برای این واریز می‌گیرد.',
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
            $admin->alert('ریختم واریز را پیوست کرد ولی پرداخت ' . Fmt::id($id) . ' هنوز تایید نشده است' . $said . '. کمی بعد «' . Words::REFRESH . '» را بزنید؛ اگر باز تایید نشده بود، واریز را در پنل ریختم ببینید.');

            return $this->view($admin, $payment);
        }
        $this->settled($admin, $payment, self::UNDELIVERED, '✅ پرداخت ' . Fmt::id($id) . ' با واریز ریختم تایید شد و ' . self::brings($payment)[1] . $said);

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

    /**
     * A decision with a note for the customer, or none (reject, cancel): the payment as the panel then has it — or, made
     * in the review queue (`$queue`), the next receipt waiting.
     *
     * @throws ApiRefusal
     */
    private function decided(Admin $admin, int $id, string $decision, ?string $note, string $done, bool $queue = false): View
    {
        $after = $queue ? self::next($admin, $id) : null;
        $payment = self::row($admin->api->post("/payments/{$id}/{$decision}", $note === null ? null : ['note' => $note]));
        $admin->notice($done);

        return $queue ? $this->onwards($admin, $id, $after) : $this->view($admin, $payment);
    }

    private function refunded(Admin $admin, int $id, ?string $note): View
    {
        $payment = self::row($admin->api->post("/payments/{$id}/refund", $note === null ? null : ['note' => $note]));
        $admin->notice(Sales::REFUND . ' پرداخت ' . Fmt::id($id) . ((Json::part($payment, 'order')['type'] ?? '') === 'wallet_topup' ? ' بازپرداخت شد' : ' به کیف پول بازپرداخت شد'));

        return $this->view($admin, $payment);
    }

    /**
     * Said after payment `$id` approved: delivered — or the delivery failed, said with why. Returns whether it was
     * delivered.
     *
     * @param array<string, mixed> $payment
     */
    private function approved(Admin $admin, int $id, array $payment): bool
    {
        return $this->settled($admin, $payment, self::UNDELIVERED, '✅ پرداخت ' . Fmt::id($id) . ' تایید شد و ' . self::brings($payment)[1]);
    }

    /**
     * Said after an approval or a retry: delivered — or the delivery failed, said with why (the retry is right there).
     * Returns whether it was delivered.
     *
     * @param array<string, mixed> $payment
     */
    private function settled(Admin $admin, array $payment, string $failed, string $done): bool
    {
        $order = Json::part($payment, 'order');
        if (($order['status'] ?? '') === 'failed') {
            $admin->alert('پرداخت ' . Fmt::id((int) ($payment['id'] ?? 0)) . ' ' . $failed . '. ' . (Json::text($order, 'notes') ?? ''));

            return false;
        }
        $admin->notice($done);

        return true;
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
            $admin->alert($number . self::UNDELIVERED . '. ' . (Json::text($order, 'notes') ?? ''));

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
        $admin->notice(Sales::REMIND . ' پرداخت ' . Fmt::id((int) ($payment['id'] ?? 0)) . ($delivery === 'emailed' ? ' یادآوری با ایمیل فرستاده شد' : ' یادآوری فرستاده شد'));
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
     * The receipt waiting for review after payment `$id` in the queue's order (newest first) — the first other one once
     * `$id` is the last, or no longer in it (decided, or 0), so receipts skipped come round again —; null when none other
     * waits.
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

        return $first;
    }

    /**
     * Refuses an approval judged on payment `$id`'s receipt in review once the payment is no longer in review — decided
     * by someone else meanwhile, or by this same button tapped again from an old window —: the API would take an owner's
     * approval of a rejected card payment as one by hand, over the rejection. Refused, the window stays as it is.
     *
     * @throws ApiRefusal
     */
    private static function stillInReview(Admin $admin, int $id): void
    {
        $status = (string) (self::payment($admin, $id)['status'] ?? '');
        if ($status !== PaymentsScreen::REVIEW) {
            throw new ApiRefusal(422, 'پرداخت ' . Fmt::id($id) . ' دیگر در انتظار بررسی نیست و وضعیتش «' . Fmt::bare(Sales::word(Sales::PAYMENT_STATUS, $status)) . '» است؛ با این دکمه تایید نشد.');
        }
    }

    /**
     * Why a payment has no receipt, as the panel says it.
     *
     * @param array<string, mixed> $payment
     */
    private static function noReceipt(array $payment): string
    {
        return match (true) {
            ($payment['kind'] ?? '') === 'online' => 'پرداخت آنلاین در صفحه درگاه؛ رسیدی در کار نیست و نتیجه‌اش را درگاه می‌گوید',
            ($payment['status'] ?? '') === 'pending' => 'مشتری هنوز رسیدی نفرستاده است',
            default => 'این پرداخت رسیدی ندارد',
        };
    }

    /**
     * Whether payment `$id` is reviewed as part of the queue: walked to in it, or opened from the shop's payments — not
     * from a customer's own list.
     */
    private static function queued(Admin $admin, int $id): bool
    {
        return ($admin->state(self::ID)[self::WALK] ?? null) === $id || CustomerRowsScreen::back($admin, self::ID, $id, PaymentsScreen::ID) === PaymentsScreen::ID;
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
        $failed = ($order['status'] ?? '') === 'failed' ? Json::text($order, 'notes') : null;
        $created = Sales::when($payment, 'created_at');
        $sent = Sales::when($receipt, 'sent_at');
        $note = static fn(?string $text): ?string => $text === null ? null : Fmt::e(Fmt::cut($text, self::NOTE_MOST));

        $view = View::make(Icons::PAYMENTS . ' پرداخت ' . Fmt::id($id), ...Sales::under(Sales::PAYMENTS));
        if ($failed !== null) {
            $view->line('⚠️ <b>تحویل انجام نشد:</b> ' . $note($failed));
        }
        $view->card([
            'وضعیت' => Fmt::e(Sales::word(Sales::PAYMENT_STATUS, $status))
                . (Json::on($payment, 'auto_approved') ? ' · خودکار، بعد از مهلت بررسی' : '')
                . ($held ? ' · ' . Sales::HELD : '')
                . ($reviewer !== null && !Json::on($payment, 'auto_approved') ? ' · ' . Sales::reviewer($reviewer, Json::text($payment, 'reviewer_name')) : ''),
            'مبلغ' => '<b>' . Fmt::money(Json::text($payment, 'amount')) . '</b>',
            'تخفیف' => Sales::discounts($order),
            ...Sales::customerFacts(Json::part($payment, 'user')),
            'بابت' => Fmt::e(implode(' · ', array_filter([Sales::word(Sales::ORDER_TYPE, $order['type'] ?? ''), $brings[1] ?? null, Json::text($order, 'plan'), Json::text($order, 'server')]))),
            'روش' => Fmt::e((string) ($payment['method'] ?? '')) . (Json::text($payment, 'summary') !== null ? ' · ' . PaymentWords::summary((string) Json::text($payment, 'summary')) : '')
                . ($online && Json::text($payment, 'gateway_label') !== null ? ' · درگاه ' . Fmt::e((string) Json::text($payment, 'gateway_label')) : ''),
            ($online ? 'شناسه در درگاه' : 'مرجع') => Json::text($payment, 'reference') !== null ? Fmt::code(Json::text($payment, 'reference')) : null,
            'مهلت پرداخت در درگاه' => $status === 'pending' && !$held && $expires !== null ? Fmt::date($expires, true) . ' (' . Sales::left($expires) . ')' : null,
            'آخرین استعلام' => Sales::when($payment, 'checked_at'),
            'ثبت' => $created,
            // The receipt sent with the payment: its moment said once.
            'رسید' => $sent !== null && $sent !== $created ? $sent : null,
            'پرداخت' => Sales::when($payment, 'paid_at'),
            'بررسی پشتیبانی' => $held ? $note($description) : null,
            'دلیل' => !$held && in_array($status, ['failed', 'cancelled'], true) ? $note($description) : null,
            'کیف پول' => Json::on($payment, 'to_wallet') ? $note($description) : null,
            'توضیح بازپرداخت' => $note(Json::text($payment, 'refund_note')),
        ]);
        if (Json::text($receipt, 'note') !== null) {
            $view->section(Sales::CHAT . ' پیام مشتری')->line('<i>' . $note(Json::text($receipt, 'note')) . '</i>');
        }

        $file = false;
        if ($receipt === []) {
            // Paid from the wallet or outside the shop (support's), the method says why there is no receipt.
            if (($payment['kind'] ?? '') !== 'instant' && ($payment['gateway'] ?? '') !== 'outside') {
                $view->line()->line('🧾 ' . self::noReceipt($payment));
            }
        } else {
            try {
                $picture = $admin->api->file("/payments/{$id}/receipt");
                if ($picture->isImage()) {
                    $view->picture($picture);
                } else {
                    $file = true;
                    $view->line()->line('🧾 رسید فایلی است که به صورت عکس نشان داده نمی‌شود؛ «' . Sales::FILE . ' فایل رسید» آن را می‌فرستد.');
                }
            } catch (ApiRefusal $refusal) {
                $view->line()->line('🧾 ' . Fmt::e($refusal->getMessage()));
            }
        }

        $actions = Json::part($payment, 'actions');
        $does = self::brings($payment)[0];
        $inReview = $status === PaymentsScreen::REVIEW;
        $queued = self::queued($admin, $id);
        $queue = $inReview && $queued;
        // A shop's admin approves a receipt in review alone (ActorRefusedException::RECEIPT_FIRST): no manual approval.
        $mayApprove = $inReview || !$admin->seat->isStaff();
        $approves = Json::on($actions, 'approve') && $mayApprove;
        $view->row(
            match (true) {
                !$approves => null,
                $queue => Button::to(Sales::APPROVE . ' تایید و ' . $does . '، بعدی', self::ID, 'okn', $id)->success(),
                default => Button::to(Sales::APPROVE . ' ' . ($inReview ? 'تایید' : 'تایید دستی') . ' و ' . $does, self::ID, 'ok', $id, ...($inReview ? [self::RECEIPT] : []))->success(),
            },
            Json::on($actions, 'reject') ? Button::to(Sales::REJECT . ' رد کردن', self::ID, 'no', $id, ...($queue ? [self::QUEUE] : []))->danger() : null,
        );
        // The queue walked a receipt after another: from a receipt in review, or the review tab's list.
        $tab = (array) ($admin->state(PaymentsScreen::ID)['f'] ?? []);
        $walks = $queued && ($inReview || ($tab['status'] ?? null) === PaymentsScreen::REVIEW);
        $view->row($walks ? Button::to(Sales::NEXT . ' رسید بعدی', self::ID, 'nx', $id) : null);
        // Attaching a Rikhtam deposit approves the payment: refused to a shop's admin exactly where approving is.
        $view->tiles(
            Json::on($actions, 'check') ? Button::to(Sales::CHECK . ' استعلام از درگاه', self::ID, 'ck', $id)->primary() : null,
            Json::on($actions, 'retry') ? Button::to(Sales::RETRY . ' تلاش دوباره برای تحویل', self::ID, 'rt', $id)->primary() : null,
            Json::on($actions, 'rikhtam') && $mayApprove ? Button::to(Sales::ATTACH . ' پیوست واریز ریختم', self::ID, 'bg', $id) : null,
            Json::on($actions, 'remind') ? Button::to(Sales::REMIND . ' یادآوری به مشتری', self::ID, 'rm', $id) : null,
            $file ? Button::to(Sales::FILE . ' فایل رسید', self::ID, 'fl', $id) : null,
        );
        $orderId = (int) ($order['id'] ?? 0);
        $service = is_numeric($order['subscription_id'] ?? null) ? (int) $order['subscription_id'] : 0;
        $view->row(Button::to(Sales::mark(Sales::ORDER_STATUS, $order['status'] ?? '') . ' سفارش ' . Fmt::id($orderId) . ' · ' . Fmt::bare(Sales::word(Sales::ORDER_STATUS, $order['status'] ?? '')), OrderScreen::ID, 'show', $orderId))
            ->row($service > 0 ? Button::to(Icons::SERVICES . ' سرویس ' . Fmt::id($service), SubscriptionScreen::ID, 'show', $service) : null)
            ->row(...Sales::customerButtons(Json::part($payment, 'user')));
        // What undoes or ends it, last.
        $view->row(
            Json::on($actions, 'cancel') ? Button::to(Sales::CANCEL . ' لغو پرداخت', self::ID, 'cx', $id)->danger() : null,
            Json::on($actions, 'refund') && $admin->seat->holds(StaffGrant::Refunds) ? Button::to(Sales::REFUND . ' ' . (($order['type'] ?? '') === 'wallet_topup' ? 'بازپرداخت شارژ' : 'بازپرداخت به کیف پول'), self::ID, 'rf', $id)->danger() : null,
        );

        // In the queue's walk, back is the payments; else the list it was opened from.
        $back = ($admin->state(self::ID)[self::WALK] ?? null) === $id ? PaymentsScreen::ID : CustomerRowsScreen::back($admin, self::ID, $id, PaymentsScreen::ID);

        return $view->back($back)->refresh(self::ID, 'show', $id);
    }
}
