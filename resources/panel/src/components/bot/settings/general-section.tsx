import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotGeneralSettingsRequest, BotSettingsData } from '@/lib/api-types'

/** The master switch, the phone rule, and the way to support. */
export function GeneralSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup({ enabled: settings.enabled, phone_required: settings.phone_required, support_contact: settings.support_contact } satisfies BotGeneralSettingsRequest, (values) =>
    api.put('/bot/settings/general', values),
  )
  const { values, set, error } = group

  return (
    <SectionCard form={group} title="عمومی" description="روشن و خاموش کردن ربات، تایید شماره مشتری و راه تماس با پشتیبانی.">
      <SwitchRow
        label="ربات فعال باشد"
        hint="خاموش که باشد، ربات به هر پیام مشتری فقط یک اطلاعیه غیرفعال بودن می‌دهد و منو را برمی‌دارد. برای تعطیلی موقت یا وقتی سرورها در دسترس نیستند."
        checked={values.enabled}
        onCheckedChange={(checked) => set('enabled', checked)}
        error={error('enabled')}
      />
      <SwitchRow
        label="تایید شماره موبایل"
        hint="مشتری قبل از هر کاری باید شماره تلگرامش را با دکمه «ارسال شماره موبایل» بفرستد؛ شماره تایپ‌شده یا شماره دیگران پذیرفته نمی‌شود. کسی که یک بار تایید کرده دوباره پرسیده نمی‌شود."
        checked={values.phone_required}
        onCheckedChange={(checked) => set('phone_required', checked)}
        error={error('phone_required')}
      />
      <Field id="bot_support_contact" label="راه ارتباط با پشتیبانی" optional error={error('support_contact')} hint="آی‌دی تلگرام یا لینکی که در بخش پشتیبانی ربات نشان داده می‌شود">
        <Input
          dir="ltr"
          autoComplete="off"
          spellCheck={false}
          value={values.support_contact}
          onChange={(e) => set('support_contact', e.target.value)}
          placeholder="@my_support"
          className="sm:max-w-sm"
        />
      </Field>
    </SectionCard>
  )
}
