import { Info } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Callout } from '@/components/callout'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotExpiredServicesSettingsRequest, BotSettingsData } from '@/lib/api-types'
import { formatDuration, wholeNumberOf } from '@/lib/format'
import { EXPIRED_SERVICES_SETTINGS } from '@/lib/limits'

/**
 * «سرویس‌های منقضی‌شده»: whether an ended service — its time or traffic ran out, or its panel no longer has it — is
 * removed from its panel and the shop by itself, and how many hours after its end. Free tests have their own rule.
 */
export function ExpiredServicesSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(
    { expired_cleanup: settings.expired_cleanup, expired_cleanup_hours: String(settings.expired_cleanup_hours) } satisfies BotExpiredServicesSettingsRequest,
    (values) => api.put('/bot/settings/expired_services', values),
  )
  const { values, set, error } = group
  const hours = wholeNumberOf(values.expired_cleanup_hours)

  return (
    <SectionCard
      form={group}
      title="حذف سرویس‌های منقضی‌شده"
      description="سرویسی که زمان یا حجمش تمام شده، یا دیگر روی پنلش نیست، منقضی‌شده است. با حذف خودکار، چنین سرویسی بعد از فاصله‌ای که تعیین می‌کنید بی‌صدا حذف می‌شود: کلاینت از پنل و سرویس از فروشگاه. به مشتری پیامی فرستاده نمی‌شود و سفارش‌هایش در سوابق می‌ماند."
    >
      <SwitchRow
        label="حذف خودکار سرویس‌های منقضی‌شده"
        hint="سرویس‌ها هر ۱۵ دقیقه بررسی می‌شوند. خاموش، سرویس‌های منقضی‌شده می‌مانند تا مشتری تمدیدشان کند یا خودتان حذفشان کنید."
        checked={values.expired_cleanup}
        onCheckedChange={(checked) => set('expired_cleanup', checked)}
        error={error('expired_cleanup')}
      />
      <Field id="expired_cleanup_hours" label="فاصله حذف (ساعت)" error={error('expired_cleanup_hours')} hint={removalHint(hours, EXPIRED_SERVICES_SETTINGS.HOURS_MAX)}>
        <Input
          dir="ltr"
          inputMode="numeric"
          value={values.expired_cleanup_hours}
          onChange={(e) => set('expired_cleanup_hours', e.target.value)}
          disabled={!values.expired_cleanup}
          className="sm:max-w-40"
        />
      </Field>
      <Callout tone="info" icon={Info}>
        سرویسی که در این فاصله تمدید شود، سرویسی که پشتیبانی غیرفعالش کرده و سرویسی که سفارشش هنوز باز است (مثلا تمدیدی که رسیدش منتظر بررسی است) حذف نمی‌شود. مشتری در ربات و سایت می‌بیند سرویس
        تمام‌شده‌اش کی حذف می‌شود. اکانت‌های تست رایگان قانون خودشان را دارند:{' '}
        <TextLink to="/tests/settings" inline className="font-medium">
          اکانت تست › تنظیمات
        </TextLink>
        .
      </Callout>
    </SectionCard>
  )
}

/**
 * When an ended service — or free test — goes, by the hours typed (`max` the server's bound); the bounds while what is
 * typed is none of them.
 */
export function removalHint(hours: number | null, max: number): string {
  if (hours === null || hours > max) return `بین 0 تا ${max} ساعت؛ 0 یعنی همان وقت که تمام شد.`
  return hours === 0 ? 'همان وقت که تمام شد حذف می‌شود.' : `${formatDuration(hours)} بعد از پایان حذف می‌شود.`
}
