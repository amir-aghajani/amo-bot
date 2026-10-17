import { Eraser, Gauge } from 'lucide-react'
import { removalHint } from '@/components/bot/settings/expired-services-section'
import { useBotSettingsGroup, useHeldWhile } from '@/components/bot/settings/use-bot-settings-group'
import { Field, FormPart } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotSettingsData, BotTestAccountsSettingsRequest } from '@/lib/api-types'
import { formatNumber, wholeNumberOf } from '@/lib/format'
import { TEST_ACCOUNT_SETTINGS } from '@/lib/limits'

/**
 * The `test_accounts` bot-settings group: the switch, the quota — tests a customer, over how many days, the shop's cap a
 * day, newcomers only — and the clean-up of the tests that ended or were never connected.
 */
export function TestSettingsCard({ settings }: { settings: BotSettingsData }) {
  const saved = {
    test_enabled: settings.test_enabled,
    test_per_user: String(settings.test_per_user),
    test_period_days: String(settings.test_period_days),
    test_daily_limit: String(settings.test_daily_limit),
    test_newcomers_only: settings.test_newcomers_only,
    test_cleanup: settings.test_cleanup,
    test_cleanup_hours: String(settings.test_cleanup_hours),
    test_unused_days: String(settings.test_unused_days),
  } satisfies BotTestAccountsSettingsRequest
  const group = useBotSettingsGroup(saved, (values) => api.put('/bot/settings/test_accounts', values))
  const { values, set, error } = group
  const perUser = wholeNumberOf(values.test_per_user)
  const days = wholeNumberOf(values.test_period_days)
  const cap = wholeNumberOf(values.test_daily_limit)
  const hours = wholeNumberOf(values.test_cleanup_hours)
  const unused = wholeNumberOf(values.test_unused_days)
  // With the clean-up off, its numbers wait as they are kept; one changed stays open, as the save still carries it —
  // and one the server refuses must be there to fix.
  const held = useHeldWhile(!values.test_cleanup, group, saved)

  return (
    <SectionCard form={group} title="قوانین اکانت تست" description="هر مشتری چند اکانت تست می‌گیرد و اکانت‌های تمام‌شده کی پاک می‌شوند. حجم و مدت هر اکانت تست را هر سرور خودش دارد.">
      <SwitchRow
        label="اکانت تست فعال باشد"
        hint="روشن که باشد، مشتری در ربات با دکمه «اکانت تست» روی یکی از سرورهایی که اکانت تست می‌دهند یک اکانت رایگان می‌گیرد. خاموش، دکمه از منوی ربات برداشته می‌شود و اکانت‌های قبلی سر جایشان می‌مانند."
        checked={values.test_enabled}
        onCheckedChange={(checked) => set('test_enabled', checked)}
        error={error('test_enabled')}
      />

      <FormPart icon={Gauge} title="سهمیه" about="مدیرهای ربات سهمیه ندارند و هر چند بار که بخواهند اکانت تست می‌گیرند.">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id="test_per_user" label="تعداد اکانت تست هر مشتری" error={error('test_per_user')} hint={`بین 1 تا ${TEST_ACCOUNT_SETTINGS.PER_USER_MAX}`}>
            <Input dir="ltr" inputMode="numeric" value={values.test_per_user} onChange={(e) => set('test_per_user', e.target.value)} />
          </Field>
          <Field
            id="test_period_days"
            label="دوره سهمیه (روز)"
            error={error('test_period_days')}
            hint={
              days !== null && days > 0
                ? `هر مشتری در هر ${formatNumber(days)} روز ${perUser !== null ? `${formatNumber(perUser)} اکانت تست` : 'همین تعداد اکانت تست'} می‌گیرد.`
                : '0 یعنی برای همیشه: هر مشتری روی هم همین تعداد می‌گیرد.'
            }
          >
            <Input dir="ltr" inputMode="numeric" value={values.test_period_days} onChange={(e) => set('test_period_days', e.target.value)} />
          </Field>
          <Field
            id="test_daily_limit"
            label="سقف روزانه فروشگاه"
            error={error('test_daily_limit')}
            hint={cap !== null && cap > 0 ? `روزی حداکثر ${formatNumber(cap)} اکانت تست، برای همه مشتری‌ها روی هم.` : '0 یعنی بدون سقف.'}
          >
            <Input dir="ltr" inputMode="numeric" value={values.test_daily_limit} onChange={(e) => set('test_daily_limit', e.target.value)} />
          </Field>
        </div>
        <SwitchRow
          label="فقط مشتری‌هایی که خرید نکرده‌اند"
          hint="روشن که باشد، مشتری‌ای که سرویس پولی خریده دیگر اکانت تست نمی‌گیرد."
          checked={values.test_newcomers_only}
          onCheckedChange={(checked) => set('test_newcomers_only', checked)}
          error={error('test_newcomers_only')}
        />
      </FormPart>

      <FormPart icon={Eraser} title="حذف خودکار" about="اکانت تست حذف‌شده از پنل و فروشگاه پاک می‌شود، ولی در سهمیه مشتری حساب می‌ماند.">
        <SwitchRow
          label="اکانت‌های تمام‌شده حذف شوند"
          hint="روشن که باشد، اکانت تستی که زمان یا حجمش تمام شده، بعد از فاصله زیر خودش حذف می‌شود. خاموش، اکانت‌ها می‌مانند تا خودتان حذفشان کنید."
          checked={values.test_cleanup}
          onCheckedChange={(checked) => set('test_cleanup', checked)}
          error={error('test_cleanup')}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field id="test_cleanup_hours" label="فاصله حذف (ساعت)" error={error('test_cleanup_hours')} hint={removalHint(hours, TEST_ACCOUNT_SETTINGS.CLEANUP_HOURS_MAX)}>
            <Input dir="ltr" inputMode="numeric" value={values.test_cleanup_hours} onChange={(e) => set('test_cleanup_hours', e.target.value)} disabled={held('test_cleanup_hours')} />
          </Field>
          <Field
            id="test_unused_days"
            label="حذف اکانت وصل‌نشده (روز)"
            error={error('test_unused_days')}
            hint={
              unused !== null && unused > 0 ? `اکانتی که ${formatNumber(unused)} روز بعد از ساخت هنوز وصل نشده حذف می‌شود.` : '0 یعنی هیچ‌وقت: اکانتی که از اولین اتصال شروع می‌شود تا وصل شدن می‌ماند.'
            }
          >
            <Input dir="ltr" inputMode="numeric" value={values.test_unused_days} onChange={(e) => set('test_unused_days', e.target.value)} disabled={held('test_unused_days')} />
          </Field>
        </div>
      </FormPart>
    </SectionCard>
  )
}
