<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «✏️ سطح، اعتبار و شرایط فروش» of an agent (the panel's AgentModal): their level, credit, end and monthly minimum — the
 * card starts from the agent read fresh (AgentScreen::load(), never the agents list's kept rows) —, then one PUT
 * /agency/agents/{id} — the end and the minimum sent only as changed here since that read (one left out is kept, a change
 * made in the panel meanwhile too), and none of them for an agent without a shop yet. The agent is told; whether they
 * were is said after.
 *
 * Actions besides the terms': `edit` (agent id).
 */
final class AgentTermsScreen extends TermsScreen
{
    public const ID = 'age';

    /** How a change reached the agent (the API's `delivery`), past «told» and «emailed» (NOT_DELIVERED). */
    private const NOT_TOLD = [
        'turned_away' => 'نماینده ربات را مسدود کرده یا حسابش دیگر نیست',
        'unreachable' => 'تلگرام در دسترس نبود',
        'refused' => 'تلگرام پیام را نپذیرفت',
        'no_telegram' => 'این نماینده تلگرام ندارد، و ایمیلی هم برایش نرفت',
    ];

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(array $state): string
    {
        return '✏️ نماینده ' . (string) ($state['name'] ?? '');
    }

    protected function saveLabel(array $state): string
    {
        return '💾 ذخیره تغییرات';
    }

    protected function intro(View $view, Admin $admin, array $state): void
    {
        $row = AgentScreen::row($admin, (int) ($state['for'] ?? 0)) ?? [];
        $bot = is_array($row['bot'] ?? null) ? $row['bot'] : null;
        $view->facts([
            'ربات' => $bot === null ? 'هنوز ندارد' : (is_string($bot['username'] ?? null) ? Fmt::ltr('@' . $bot['username']) : 'بدون توکن'),
            'حجم باقی‌مانده' => $bot === null ? null : Fmt::bytes((int) ($bot['traffic_balance'] ?? 0)),
            'موجودی کیف پول' => Fmt::money((string) ($row['balance'] ?? '0')),
        ]);
    }

    protected function start(Admin $admin, string $action, array $args): View
    {
        $row = $action === 'edit' ? AgentScreen::load($admin, self::id($args)) : null;
        if ($row === null) {
            return $admin->open(AgentsScreen::ID);
        }
        $standing = is_array($row['standing'] ?? null) ? $row['standing'] : null;
        $expires = AgencyEnd::of(is_string($standing['expires_at'] ?? null) ? $standing['expires_at'] : null);
        $minimum = self::toman($standing['monthly_minimum'] ?? '0');
        $admin->forget(self::ID);
        $admin->remember(self::ID, [
            'for' => (int) $row['id'],
            'name' => Words::user($row['user'] ?? null),
            'level_id' => (string) ($row['level']['id'] ?? ''),
            'credit' => self::toman($row['credit_limit'] ?? '0'),
            'expires' => $expires,
            'minimum' => $minimum,
            'selling' => $standing !== null,
            'kept_expires' => $expires,
            'kept_minimum' => $minimum,
        ]);

        return $this->card($admin, $admin->state(self::ID));
    }

    protected function save(Admin $admin, array $state): View
    {
        $id = (int) $state['for'];
        $body = ['level_id' => (string) ($state['level_id'] ?? ''), 'credit_limit' => (string) ($state['credit'] ?? '0')];
        if (Json::on($state, 'selling')) {
            if (self::moment($state) !== ($state['kept_expires'] ?? null)) {
                $body['expires_at'] = self::moment($state);
            }
            if ((string) ($state['minimum'] ?? '0') !== (string) ($state['kept_minimum'] ?? '0')) {
                $body['monthly_minimum'] = (string) ($state['minimum'] ?? '0');
            }
        }
        $answer = $admin->api->put("/agency/agents/{$id}", $body);
        AgentScreen::keep($admin, (array) ($answer['agent'] ?? []));
        $admin->forget(self::ID);
        $delivery = is_string($answer['delivery'] ?? null) ? $answer['delivery'] : null;
        $admin->notice(match ($delivery) {
            null => 'شرایط نماینده تغییری نکرد',
            'told' => '✅ نمایندگی به‌روز شد و به نماینده خبر داده شد',
            'emailed' => '✅ نمایندگی به‌روز شد و خبرش به نماینده با ایمیل فرستاده شد',
            default => '⚠️ نمایندگی به‌روز شد، ولی به نماینده خبر داده نشد: ' . (self::NOT_TOLD[$delivery] ?? $delivery),
        });

        return $admin->open(AgentScreen::ID, 'show', [$id]);
    }

    protected function leave(Admin $admin, array $state): View
    {
        return isset($state['for']) ? $admin->open(AgentScreen::ID, 'show', [(int) $state['for']]) : $admin->open(AgentsScreen::ID);
    }
}
