import { TriangleAlert } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Callout } from '@/components/callout'
import { CheckList } from '@/components/check-list'
import { Field, FieldGroup } from '@/components/field'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotGeneralSettingsRequest, BotSettingsData } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** A moment the number may be asked at, while it is asked only at some: the group's switch of it. */
type PhoneMoment = Extract<keyof BotGeneralSettingsRequest, `phone_on_${string}`>

/** Where the number is asked: before everything, or only at the moments ticked. */
const SCOPES: PageTab<BotGeneralSettingsRequest['phone_scope']>[] = [
  { value: 'everywhere', label: 'کل ربات' },
  { value: 'moments', label: 'فقط موقع این کارها' },
]

/** The moments, in the bot's order of things: a renewal counts its extras, a card-to-card payment whatever it pays for. */
const MOMENTS: { value: PhoneMoment; label: string }[] = [
  { value: 'phone_on_purchase', label: 'خرید سرویس' },
  { value: 'phone_on_renewal', label: 'تمدید (و حجم، زمان یا کاربر اضافه)' },
  { value: 'phone_on_test', label: 'گرفتن اکانت تست' },
  { value: 'phone_on_card', label: 'پرداخت کارت به کارت' },
]

/** The group as its form holds it — every field, as the save sends them all. */
function draftOf(settings: BotSettingsData) {
  return {
    enabled: settings.enabled,
    phone_required: settings.phone_required,
    phone_scope: settings.phone_scope,
    phone_on_purchase: settings.phone_on_purchase,
    phone_on_renewal: settings.phone_on_renewal,
    phone_on_test: settings.phone_on_test,
    phone_on_card: settings.phone_on_card,
    phone_iran_only: settings.phone_iran_only,
    support_contact: settings.support_contact,
    support_header: settings.support_header,
  } satisfies BotGeneralSettingsRequest
}

/**
 * The master switch; the phone rule (before everything, or only at the moments ticked; Iranian numbers alone or any);
 * the way to support, which card payments sent to support's private chat need (with that switch on, an emptied contact
 * is warned of, since the bot then shows the card again); and whether a message support writes a customer
 * («ارسال پیام») comes under the bot's heading for it.
 */
export function GeneralSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(
    draftOf(settings),
    (values) => api.put('/bot/settings/general', values),
    // The owner's dashboard says the bot is off by its switch («غیرفعال از تنظیمات»), and a card-to-card method whether
    // its card is given in support's chat — which the contact here decides with «پرداخت کارت به کارت» (`to_support`).
    { invalidates: [queryKeys.system, queryKeys.paymentMethods] },
  )
  const { values, set, patch, error } = group
  const cardsBack = settings.card_to_support && values.support_contact.trim() === ''
  const ticked = MOMENTS.filter((moment) => values[moment.value]).map((moment) => moment.value)

  return (
    <SectionCard form={group} title="عمومی" description="روشن و خاموش کردن ربات، تایید شماره مشتری، راه تماس با پشتیبانی و پیام پشتیبانی به مشتری.">
      <SwitchRow
        label="ربات فعال باشد"
        hint="خاموش که باشد، ربات به هر پیام مشتری فقط یک اطلاعیه غیرفعال بودن می‌دهد و منو را برمی‌دارد، و وب‌سایت فروشگاه هم سفارش نمی‌گیرد. برای تعطیلی موقت یا وقتی سرورها در دسترس نیستند."
        checked={values.enabled}
        onCheckedChange={(checked) => set('enabled', checked)}
        error={error('enabled')}
      />
      <SwitchRow
        label="تایید شماره موبایل"
        hint="مشتری باید شماره تلگرامش را با دکمه «ارسال شماره موبایل» بفرستد؛ شماره تایپ‌شده یا شماره دیگران پذیرفته نمی‌شود. کسی که یک بار تایید کرده دوباره پرسیده نمی‌شود. از مدیرهای ربات و مشتری‌هایی که در صفحه‌شان «معاف از ارسال شماره موبایل» روشن است هیچ‌وقت پرسیده نمی‌شود."
        checked={values.phone_required}
        onCheckedChange={(checked) => set('phone_required', checked)}
        error={error('phone_required')}
      />
      {/* Where it is asked, while it is: hidden with the rule off, the save still carries it as it stands. */}
      {values.phone_required && (
        <>
          <FieldGroup
            label="کجا پرسیده شود"
            error={error('phone_scope')}
            hint={
              values.phone_scope === 'everywhere'
                ? 'تا مشتری شماره‌اش را نفرستد، ربات هیچ کاری برایش انجام نمی‌دهد و مینی‌اپ هم سفارش، اکانت تست یا جایزه‌ای به او نمی‌دهد. وب‌سایت فروشگاه شماره را فقط قبل از اکانت تست رایگان و چرخاندن گردونه شانس می‌خواهد.'
                : 'ربات و مینی‌اپ آزادانه کار می‌کنند و شماره فقط قبل از کارهایی که تیک زده‌اید خواسته می‌شود. وب‌سایت فروشگاه فقط قبل از اکانت تست رایگان می‌پرسد، اگر «گرفتن اکانت تست» تیک خورده باشد.'
            }
          >
            <PageTabs as="choice" size="sm" className="w-fit" aria-label="کجا شماره موبایل پرسیده شود" value={values.phone_scope} tabs={SCOPES} onChange={(scope) => set('phone_scope', scope)} />
          </FieldGroup>
          {values.phone_scope === 'moments' && (
            <CheckList
              label="کارهایی که شماره می‌خواهند"
              options={MOMENTS}
              value={ticked}
              onChange={(next) => patch(Object.fromEntries(MOMENTS.map((moment) => [moment.value, next.includes(moment.value)])))}
              error={MOMENTS.map((moment) => error(moment.value)).find(Boolean)}
              hint={
                ticked.length === 0
                  ? 'هیچ کاری تیک نخورده است: شماره از هیچ مشتری‌ای پرسیده نمی‌شود.'
                  : 'سر این کارها ربات اول شماره را می‌خواهد و تا مشتری آن را نفرستد، آن کار انجام نمی‌شود. «پرداخت کارت به کارت» یعنی پرداخت با روش کارت به کارت خود فروشگاه (با رسید یا تایید خودکار واریز)، برای هر چیزی، شارژ کیف پول هم؛ درگاه‌های آنلاین شماره نمی‌خواهند.'
              }
            />
          )}
          <SwitchRow
            label={'فقط شماره‌های ایران (‎+98)'}
            hint="روشن که باشد، شماره کشورهای دیگر پذیرفته نمی‌شود و ربات به مشتری می‌گوید فقط شماره ایران قبول است. مشتری‌ای که پیش‌تر با شماره خارجی تایید شده، دوباره شماره را می‌خواهد. برای کسی که شماره ایرانی ندارد، او را در صفحه‌اش از ارسال شماره معاف کنید."
            checked={values.phone_iran_only}
            onCheckedChange={(checked) => set('phone_iran_only', checked)}
            error={error('phone_iran_only')}
          />
        </>
      )}
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
      {cardsBack && (
        <Callout tone="warning" icon={TriangleAlert}>
          «هدایت پرداخت کارت به کارت به پیوی پشتیبانی» روشن است و بدون راه ارتباط با پشتیبانی کار نمی‌کند: تا این خانه خالی است، ربات و سایت شماره کارت را مثل قبل نشان می‌دهند.
        </Callout>
      )}
      <SwitchRow
        label="عنوان پیام پشتیبانی"
        hint="روشن که باشد، پیامی که از صفحه کاربر یا کارت او در گروه گزارش‌ها با «ارسال پیام» می‌فرستید زیر «📩 پیام از طرف پشتیبانی:» می‌رسد (این متن در «متن‌های ربات» عوض می‌شود)؛ خاموش، فقط خود متن."
        checked={values.support_header}
        onCheckedChange={(checked) => set('support_header', checked)}
        error={error('support_header')}
      />
    </SectionCard>
  )
}
