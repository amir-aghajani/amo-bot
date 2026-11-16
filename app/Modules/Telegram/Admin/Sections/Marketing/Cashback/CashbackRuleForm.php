<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Cashback;

use App\Modules\Telegram\Admin\Sections\Marketing\Form\FormScreen;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\Step;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «➕ افزودن قانون کش‌بک» / «✏️ ویرایش» (the panel's RuleForm): the rule's percent, its kinds of order (at least one), a
 * customer group and a way to pay (or every one). One save: POST /cashback/rules, or the rule's PUT — which keeps its
 * switch (the rule's own screen sets it).
 */
final class CashbackRuleForm extends FormScreen
{
    public const ID = 'cbf';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(int $id, array $draft): string
    {
        return $id === 0 ? Words::add('قانون کش‌بک') : Words::EDIT . ' قانون کش‌بک';
    }

    protected function path(): array
    {
        return Marketing::in(Marketing::CASHBACK);
    }

    protected function steps(Admin $admin): array
    {
        return [
            Step::percent('percent', 'درصد کش‌بک', 'از مبلغی که سفارش پرداخت کرده، بعد از تحویل به کیف پول مشتری برمی‌گردد؛ رقم به تومان پایین گرد می‌شود.')->asks('چند درصد برگردد؟')->range(1, 100)->quick('۵٪', 5)->quick('۱۰٪', 10),
            Step::multi('order_types', 'نوع سفارش', Marketing::KINDS, 'شارژ کیف پول و خرید حجم نمایندگی کش‌بک نمی‌گیرند.')->asks('برای چه سفارش‌هایی باشد؟'),
            Step::choice('customer_group_id', 'گروه مشتری', static fn(): array => ['' => 'همه مشتری‌ها'] + Marketing::groups($admin), 'گروه‌ها در «فروشگاه › گروه‌های کاربران» ساخته می‌شوند.')->asks('برای کدام مشتری‌ها باشد؟'),
            Step::choice('payment_method_id', 'روش پرداخت', static fn(): array => ['' => 'همه روش‌ها'] + Marketing::methods($admin), 'روشی که سفارش با آن پرداخت شده است.')->asks('با کدام روش پرداخت باشد؟'),
        ];
    }

    protected function blank(Admin $admin): array
    {
        // What a new rule is for to start with: purchases and renewals (the panel's).
        return ['percent' => null, 'order_types' => ['purchase', 'renewal'], 'customer_group_id' => null, 'payment_method_id' => null];
    }

    protected function row(Admin $admin, int $id): ?array
    {
        return Marketing::one($admin, '/cashback/rules', 'rules', $id);
    }

    protected function draftOf(array $row): array
    {
        return [
            'percent' => is_int($row['percent'] ?? null) ? $row['percent'] : null,
            'order_types' => is_array($row['order_types'] ?? null) ? array_values($row['order_types']) : [],
            'customer_group_id' => is_array($row['customer_group'] ?? null) ? (int) ($row['customer_group']['id'] ?? 0) : null,
            'payment_method_id' => is_array($row['payment_method'] ?? null) ? (int) ($row['payment_method']['id'] ?? 0) : null,
        ];
    }

    protected function back(int $id): array
    {
        return $id === 0 ? [CashbackRulesScreen::ID, 'show', []] : [CashbackRuleScreen::ID, 'show', [$id]];
    }

    protected function body(array $draft): array
    {
        return [
            'percent' => $draft['percent'] ?? null,
            'order_types' => $draft['order_types'] ?? [],
            'customer_group_id' => $draft['customer_group_id'] ?? null,
            'payment_method_id' => $draft['payment_method_id'] ?? null,
        ];
    }

    protected function store(Admin $admin, int $id, array $body): array
    {
        $answer = $id === 0 ? $admin->api->post('/cashback/rules', $body) : $admin->api->put("/cashback/rules/{$id}", $body);

        return Json::part($answer, 'rule');
    }

    protected function done(Admin $admin, array $row, bool $created): View
    {
        $admin->notice($created ? '✅ قانون کش‌بک اضافه شد' : '✅ تغییرهای قانون کش‌بک ذخیره شد');

        return $admin->open(CashbackRuleScreen::ID, 'show', [(int) ($row['id'] ?? 0)]);
    }
}
