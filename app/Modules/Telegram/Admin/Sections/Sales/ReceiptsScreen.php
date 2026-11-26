<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use Illuminate\Support\Carbon;

/**
 * «☑️ رد چند رسید» (the panel's selection on the payments list and its DismissReceiptsModal): the receipts waiting for
 * review, each ticked or not at a tap — a page at once too —, then the ticked ones rejected together, or every one waiting; asked first, with the panel's words of what it does, and with a reason
 * for the customers or without (POST /payments/dismiss, a hundred a request; «همه» round after round, held to the
 * receipts that had come when its confirmation was shown — one sent while the admin read it is left for them to see).
 * The way to review them one after another instead (PaymentScreen's queue) is beside them.
 *
 * Actions: `t` (id), `pa` (the page), `pn` (none), `dis` (asks) → `dig` | `din`; `all` (asks) → `alg` | `aln` (each with
 * the moment the confirmation was shown, in Unix seconds).
 */
final class ReceiptsScreen extends ListScreen
{
    public const ID = 'pyx';

    /** The screen's title, as the payments list's button says it. */
    public const TITLE = '☑️ رد چند رسید';

    /** The way to reject every receipt waiting. */
    public const ALL = Sales::REJECT . ' رد همه رسیدهای در انتظار';

    /** The most receipts one request names (the panel's RECEIPT_DISMISSALS.MOST). */
    private const MOST = 100;

    /** The most rounds «همه» goes through: a queue that keeps filling is not chased for ever. */
    private const ROUNDS_MOST = 50;

    /** What a dismissal does to each receipt, said before it is confirmed (the panel's). */
    private const DOES = 'هر رسید رد می‌شود، همان‌طور که «رد کردن» یک رسید را رد می‌کند: پرداختش ناموفق می‌شود و سفارشش باز می‌ماند تا مشتری دوباره بپردازد یا خودش منقضی شود. مشتری در تلگرام یا با ایمیل خبردار می‌شود، عکس رسیدی که از سایت فرستاده شده پاک می‌شود و در گروه گزارش‌ها یک خط برای همه ثبت می‌شود.';

    /**
     * @var list<int> The receipts ticked, as this showing reads them (show())
     */
    private array $ticked = [];

    /** How many receipts wait, as this showing's meta said it (summary()). */
    private int $waiting = 0;

    public function show(Admin $admin, array $args): View
    {
        $this->ticked = self::tickedOf($admin);

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return Sales::under(Sales::PAYMENTS);
    }

    protected function parent(): string
    {
        return PaymentsScreen::ID;
    }

    protected function endpoint(): string
    {
        return '/payments';
    }

    protected function key(): string
    {
        return 'payments';
    }

    protected function fixed(): array
    {
        return ['status' => PaymentsScreen::REVIEW];
    }

    protected function searchable(): bool
    {
        return false;
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function noun(): string
    {
        return 'رسید در انتظار بررسی';
    }

    protected function empty(): string
    {
        return 'فعلا رسیدی منتظر بررسی نیست.';
    }

    protected function summary(array $meta): array
    {
        $this->waiting = is_numeric($meta['total'] ?? null) ? (int) $meta['total'] : 0;
        if ($this->waiting === 0) {
            // Nothing to pick: the list says so (empty()).
            return [];
        }

        return ['<i>رسیدهایی را که می‌خواهید با هم رد کنید انتخاب کنید.</i>', '☑️ ' . Fmt::number(count($this->ticked)) . ' رسید انتخاب شده'];
    }

    protected function item(array $row): Button
    {
        $id = (int) ($row['id'] ?? 0);

        return Button::to(Words::tick(in_array($id, $this->ticked, true)) . Sales::paymentRow($row), self::ID, 't', $id);
    }

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->waiting === 0) {
            return;
        }
        $view->row(
            Button::to(Words::TICK_PAGE, self::ID, 'pa'),
            $this->ticked === [] ? null : Button::to(Words::TICK_NONE, self::ID, 'pn'),
        );
        if ($this->ticked !== []) {
            $view->row(Button::to(Sales::REJECT . ' رد ' . Fmt::number(count($this->ticked)) . ' رسید انتخاب‌شده', self::ID, 'dis')->danger());
        }
        $view->row(Button::to(PaymentsScreen::START_REVIEW, PaymentScreen::ID, 'nx', 0))
            ->row(Button::to(self::ALL, self::ID, 'all')->danger());
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        $ticked = self::tickedOf($admin);
        switch ($action) {
            case 't':
                $id = self::id($args);
                $ticked = in_array($id, $ticked, true) ? array_values(array_diff($ticked, [$id])) : [...$ticked, $id];
                $admin->remember(self::ID, ['ticked' => $ticked]);
                break;
            case 'pa':
                $rows = (array) ($admin->state(self::ID)['rows'] ?? []);
                $admin->remember(self::ID, ['ticked' => array_values(array_unique([...$ticked, ...array_map(intval(...), array_keys($rows))]))]);
                break;
            case 'pn':
                $admin->remember(self::ID, ['ticked' => []]);
                break;
            case 'dis':
                if ($ticked === []) {
                    break;
                }

                return Sales::choose(
                    $admin,
                    Fmt::number(count($ticked)) . ' رسید انتخاب‌شده رد شود؟',
                    'رسیدهای انتخاب‌شده رد می‌شوند. ' . self::DOES,
                    true,
                    Button::to(Sales::WITH_NOTE, self::ID, 'din'),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'dig'),
                    Button::to(Words::CANCEL, self::ID),
                );
            case 'all':
                $waiting = (int) (Json::part($admin->api->get('/payments', ['status' => PaymentsScreen::REVIEW]), 'meta')['total'] ?? 0);
                if ($waiting === 0) {
                    $admin->notice('فعلا رسیدی منتظر بررسی نیست');

                    return $admin->open(PaymentsScreen::ID);
                }
                // Held to what had come by now: a receipt sent while the admin reads this is not rejected unseen.
                $shown = Carbon::now()->getTimestamp();

                return Sales::choose(
                    $admin,
                    'همه رسیدهای در انتظار رد شود؟',
                    'همه ' . Fmt::number($waiting) . ' رسیدی که الان در انتظار بررسی است رد می‌شود؛ رسیدی که از این لحظه برسد می‌ماند. ' . self::DOES,
                    true,
                    Button::to(Sales::WITH_NOTE, self::ID, 'aln', $shown),
                    Button::to(Sales::WITHOUT_NOTE, self::ID, 'alg', $shown),
                    Button::to(Words::CANCEL, PaymentsScreen::ID),
                );
            case 'dig':
                return $this->dismissed($admin, $this->dismiss($admin, ['ids' => $ticked], ''));
            case 'alg':
                return $this->dismissed($admin, $this->dismiss($admin, ['all' => self::id($args)], ''));
            case 'din':
            case 'aln':
                return $admin->ask((new Prompt('دلیل رد برای مشتری', self::ID, $action, $action === 'aln' ? [self::id($args)] : [], 'هر مشتری این متن را با خبر رد رسیدش می‌بیند.', Button::to(Words::CANCEL, self::ID)))
                    ->example('رسید تکراری بود')
                    ->limits(Sales::NOTE_LIMIT));
        }

        return $this->show($admin, []);
    }

    protected function typed(Admin $admin, string $action, array $args, Answer $answer): View
    {
        return match ($action) {
            'din' => $this->dismissed($admin, $this->dismiss($admin, ['ids' => self::tickedOf($admin)], $answer->text())),
            'aln' => $this->dismissed($admin, $this->dismiss($admin, ['all' => self::id($args)], $answer->text())),
            default => $this->show($admin, []),
        };
    }

    /**
     * @return list<int>
     */
    private static function tickedOf(Admin $admin): array
    {
        $ticked = $admin->state(self::ID)['ticked'] ?? [];

        return is_array($ticked) ? array_values(array_map(intval(...), array_filter($ticked, is_numeric(...)))) : [];
    }

    /**
     * The receipts `$which` names rejected with `$note`: the ticked ones a hundred a request, or every one waiting round
     * after round until none is left of those that had come by `all` — the moment the confirmation was shown (Unix
     * seconds; 0, a button of an older version: when the first round answers). [rejected, skipped].
     *
     * @param array{ids?: list<int>, all?: int} $which
     * @return array{0: int, 1: int}
     */
    private function dismiss(Admin $admin, array $which, string $note): array
    {
        $dismissed = 0;
        $skipped = 0;
        if (isset($which['ids'])) {
            foreach (array_chunk($which['ids'], self::MOST) as $ids) {
                $answer = $admin->api->post('/payments/dismiss', ['ids' => $ids, 'note' => $note]);
                $dismissed += (int) ($answer['dismissed'] ?? 0);
                $skipped += (int) ($answer['skipped'] ?? 0);
            }

            return [$dismissed, $skipped];
        }
        $until = ($which['all'] ?? 0) > 0 ? Carbon::createFromTimestamp($which['all'])->toIso8601String() : null;
        for ($round = 0; $round < self::ROUNDS_MOST; $round++) {
            $answer = $admin->api->post('/payments/dismiss', ['all' => true, 'note' => $note] + ($until === null ? [] : ['until' => $until]));
            $dismissed += (int) ($answer['dismissed'] ?? 0);
            $skipped += (int) ($answer['skipped'] ?? 0);
            $until ??= is_string($answer['until'] ?? null) ? $answer['until'] : null;
            if ((int) ($answer['left'] ?? 0) === 0 || (int) ($answer['dismissed'] ?? 0) === 0) {
                break;
            }
        }

        return [$dismissed, $skipped];
    }

    /**
     * Said once it all went through — how many, and how many had been decided meanwhile —, the ticks gone, the payments
     * list as it now stands.
     *
     * @param array{0: int, 1: int} $outcome
     */
    private function dismissed(Admin $admin, array $outcome): View
    {
        [$dismissed, $skipped] = $outcome;
        $decided = $skipped > 0 ? '؛ ' . Fmt::number($skipped) . ' رسید پیش‌تر تعیین تکلیف شده بود' : '';
        $admin->notice(($dismissed > 0 ? '✅ ' . Fmt::number($dismissed) . ' رسید رد شد' : '⚠️ رسیدی رد نشد') . $decided);
        $admin->forget(self::ID);

        return $admin->open(PaymentsScreen::ID);
    }
}
