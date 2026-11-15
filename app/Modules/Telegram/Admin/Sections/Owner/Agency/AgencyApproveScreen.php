<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\Sections\Owner\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * Approving an agency request (the panel's approve dialog): the level the customer becomes an agent on — the first one
 * offered —, the credit they start with, when their agency ends and their monthly minimum, each starting as the
 * program's rules say (AgencySettingsScreen), then «✔️ تایید و نماینده کردن»: one POST …/approve.
 *
 * Actions besides the terms': `new` (request id).
 */
final class AgencyApproveScreen extends TermsScreen
{
    public const ID = 'agra';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(array $state): string
    {
        return '✅ نمایندگی ' . (string) ($state['name'] ?? '');
    }

    protected function saveLabel(array $state): string
    {
        return '✔️ تایید و نماینده کردن';
    }

    protected function note(array $state): string
    {
        return 'پیام تایید، با سطح، قیمت هر گیگابایت، اعتبار و شرایط فروش، در ربات برای مشتری فرستاده می‌شود.';
    }

    protected function intro(View $view, Admin $admin, array $state): void
    {
        if (is_string($state['note'] ?? null) && $state['note'] !== '') {
            $view->line('توضیح مشتری:')->line('<blockquote>' . Fmt::e($state['note']) . '</blockquote>');
        }
    }

    protected function start(Admin $admin, string $action, array $args): View
    {
        $row = $action === 'new' ? AgencyRequestScreen::row($admin, self::id($args)) : null;
        if ($row === null) {
            return $admin->open(AgencyRequestsScreen::ID);
        }
        $rules = (array) ($admin->api->get('/agency/settings')['settings'] ?? []);
        $first = $this->all($admin)[0] ?? null;
        $days = (int) ($rules['term_days'] ?? 0);
        $admin->forget(self::ID);
        $admin->remember(self::ID, [
            'for' => (int) $row['id'],
            'name' => Words::user($row['user'] ?? null),
            'note' => is_string($row['note'] ?? null) ? $row['note'] : '',
            'level_id' => $first === null ? '' : (string) ($first['id'] ?? ''),
            'credit' => self::toman($rules['default_credit'] ?? '0'),
            'expires' => $days > 0 ? AgencyEnd::in($days) : null,
            'minimum' => self::toman($rules['monthly_minimum'] ?? '0'),
            'selling' => true,
        ]);

        return $this->card($admin, $admin->state(self::ID));
    }

    protected function save(Admin $admin, array $state): View
    {
        $id = (int) $state['for'];
        $row = (array) ($admin->api->post("/agency/requests/{$id}/approve", [
            'level_id' => (string) ($state['level_id'] ?? ''),
            'credit_limit' => (string) ($state['credit'] ?? '0'),
            'expires_at' => self::moment($state),
            'monthly_minimum' => (string) ($state['minimum'] ?? '0'),
        ])['request'] ?? []);
        AgencyRequestScreen::keep($admin, $row);
        $admin->forget(self::ID);
        $admin->notice('✅ ' . (string) ($state['name'] ?? '') . ' نماینده شد');

        return $admin->open(AgencyRequestScreen::ID, 'show', [$id]);
    }

    protected function leave(Admin $admin, array $state): View
    {
        return isset($state['for']) ? $admin->open(AgencyRequestScreen::ID, 'show', [(int) $state['for']]) : $admin->open(AgencyRequestsScreen::ID);
    }
}
