<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Sales;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «📩 واریزهای پیامکی» (the payments page's «واریزهای پیامکی»): the bank deposits the phone app forwarded or Rikhtam told
 * («تایید خودکار واریز»), newest first, by what came of each (the tabs — what waits on support first) or searched by sum or words; a
 * row opens with the SMS as it came, or what Rikhtam sent (DepositScreen). The panel lists them alone: the page's rows are kept for it. While
 * «تایید خودکار واریز» is off, the owner and an agent read so over the list, with the way to its switch.
 */
final class DepositsScreen extends ListScreen
{
    public const ID = 'dp';

    /** The panel's tabs: «همه», a deposit no payment asks, one several do, then the rest. */
    private const TABS = [
        self::ANY => 'همه',
        'unmatched' => '❓ بدون پرداخت',
        'ambiguous' => '⚖️ چند پرداخت',
        'matched' => '✅ تایید شده',
        'ignored' => '🙈 نادیده',
        'test' => '🧪 آزمایشی',
    ];

    /** What a tab with nothing in it says. */
    private const NONE = [
        'unmatched' => 'فعلا واریزی بدون پرداخت نیست.',
        'ambiguous' => 'فعلا واریزی با چند پرداخت هم‌مبلغ نیست.',
        'matched' => 'هنوز واریزی پرداختی را تایید نکرده است.',
        'ignored' => 'پیامک نادیده‌ای نیست.',
        'test' => 'هنوز پیام آزمایشی نرسیده است.',
    ];

    /** The payment methods' screen, where «تایید خودکار واریز» is switched on (the Owner section's). */
    private const METHODS = 'pm';

    /** Whether «تایید خودکار واریز» is off, as this showing read it (show()). */
    private bool $off = false;

    /**
     * @var array<string, mixed> The list's state as this showing reads it
     */
    private array $state = [];

    public function show(Admin $admin, array $args): View
    {
        $this->state = $admin->state(self::ID);
        $this->off = false;
        if ($admin->seat->configures()) {
            try {
                $this->off = ($admin->api->get('/deposits/settings')['enabled'] ?? true) === false;
            } catch (ApiRefusal) {
                // Its settings unread: the list says enough.
            }
        }

        return parent::show($admin, $args);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '📩 واریزهای پیامکی';
    }

    protected function path(): array
    {
        return ['مدیریت', 'فروش'];
    }

    protected function parent(): string
    {
        return 'hl';
    }

    protected function endpoint(): string
    {
        return '/deposits';
    }

    protected function key(): string
    {
        return 'deposits';
    }

    protected function filters(): array
    {
        return ['status' => self::TABS];
    }

    protected function searchHint(): string
    {
        return 'مبلغ به تومان یا ریال، یا متن پیامک';
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function summary(array $meta): array
    {
        $lines = ['پیامک‌های واریز بانک که فوراپ از گوشی فروشگاه یا ریختم به فروشگاه رسانده، تازه‌ترین اول.'];
        if ($this->off) {
            $lines[] = '⚠️ تایید خودکار واریز خاموش است و پیامک‌های بانک پرداختی را تایید نمی‌کنند.';
        }

        return $lines;
    }

    protected function empty(): string
    {
        $set = Json::part($this->state, 'f');
        $tab = is_string($set['status'] ?? null) ? $set['status'] : self::ANY;

        return match (true) {
            (string) ($this->state['search'] ?? '') !== '' => 'واریزی پیدا نشد؛ با این جستجو چیزی نیست.',
            isset(self::NONE[$tab]) => self::NONE[$tab],
            default => 'هنوز پیامکی نرسیده است؛ وقتی تایید خودکار واریز روشن و فوراپ یا ریختم وصل باشد، هر پیامک واریز بانک این‌جا می‌آید.',
        };
    }

    protected function extra(View $view, Admin $admin): void
    {
        if ($this->off && $admin->opens(self::METHODS)) {
            $view->row(Button::to('💳 روشن کردن در روش‌های پرداخت', self::METHODS));
        }
    }

    protected function item(array $row): Button
    {
        $amount = Json::text($row, 'amount');

        return Button::to(
            Sales::mark(Sales::DEPOSIT_STATUS, $row['status'] ?? '') . ' ' . ($amount === null ? 'بدون مبلغ' : Fmt::money($amount)) . ' · ' . (Json::text($row, 'bank') ?? Json::text($row, 'sender') ?? '—')
                . ' · ' . Fmt::date(Json::text($row, 'received_at') ?? Json::text($row, 'created_at'), true),
            DepositScreen::ID,
            'show',
            (int) ($row['id'] ?? 0),
        );
    }
}
