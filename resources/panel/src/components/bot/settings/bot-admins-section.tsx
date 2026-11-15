import { Info, TriangleAlert } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Callout } from '@/components/callout'
import { FieldGroup } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { api } from '@/lib/api'
import type { BotBotAdminsSettingsRequest, BotSettingsData } from '@/lib/api-types'

/** A grant's switch, by its field (`bot_admins_grant_<grant>`). */
type GrantField = Exclude<keyof BotBotAdminsSettingsRequest, 'bot_admins_enabled'>

/**
 * What each grant lets the shop's admins do in the bot, in the grants' own order (StaffGrant) — the website's «مدیران
 * سایت» list, worded as the bot's own «🛡 مدیران ربات» words it.
 */
const GRANTS: { field: GrantField; label: string; hint: string }[] = [
  {
    field: 'bot_admins_grant_catalog',
    label: 'پلن‌ها و دسته‌بندی‌ها',
    hint: 'ساختن، ویرایش، مرتب کردن، روشن و خاموش کردن و حذف پلن‌ها و دسته‌بندی‌ها؛ و گروه‌های مشتری و گذاشتن مشتری در گروه «همکار».',
  },
  { field: 'bot_admins_grant_wallet', label: 'کیف پول مشتری', hint: 'افزایش و کم کردن موجودی کیف پول مشتری.' },
  { field: 'bot_admins_grant_refunds', label: 'بازپرداخت', hint: 'بازپرداخت پرداخت مشتری.' },
  { field: 'bot_admins_grant_payments', label: 'رد یکجای رسیدها', hint: 'رد کردن یکجای رسیدهای در انتظار بررسی؛ رد کردن رسیدها یکی‌یکی همیشه ممکن است.' },
  { field: 'bot_admins_grant_extend', label: 'افزایش زمان و حجم سرویس', hint: 'دادن روز و حجم بیشتر به سرویس یک مشتری.' },
  { field: 'bot_admins_grant_manual_orders', label: 'افزودن سفارش برای مشتری', hint: 'ساختن سرویس برای مشتری به صورت رایگان، از کیف پولش یا با پولی که بیرون از فروشگاه گرفته شده.' },
  { field: 'bot_admins_grant_delete', label: 'حذف یا انتقال سرویس', hint: 'حذف همیشگی سرویس مشتری، یا انتقال آن به حساب مشتری دیگر.' },
  { field: 'bot_admins_grant_account_security', label: 'امنیت حساب مشتری', hint: 'خاموش کردن ورود دو مرحله‌ای حساب وب‌سایت مشتری و خارج کردنش از همه دستگاه‌ها.' },
]

/** The group as its card holds it: the switch and every grant's, as the request takes them. */
function draftOf(settings: BotSettingsData): BotBotAdminsSettingsRequest {
  return {
    bot_admins_enabled: settings.bot_admins_enabled,
    bot_admins_grant_catalog: settings.bot_admins_grant_catalog,
    bot_admins_grant_wallet: settings.bot_admins_grant_wallet,
    bot_admins_grant_refunds: settings.bot_admins_grant_refunds,
    bot_admins_grant_payments: settings.bot_admins_grant_payments,
    bot_admins_grant_extend: settings.bot_admins_grant_extend,
    bot_admins_grant_manual_orders: settings.bot_admins_grant_manual_orders,
    bot_admins_grant_delete: settings.bot_admins_grant_delete,
    bot_admins_grant_account_security: settings.bot_admins_grant_account_security,
  }
}

/**
 * «مدیران ربات» (both panels, each shop its own): whether the shop's admins — its customers whose role is admin —
 * manage it from the bot's private chat («⚙️ مدیریت»), and what beyond the daily work, a switch a grant — the website's
 * «مدیران سایت» card, for the bot. Saved as the group's PUT; the bot asks it on each message, so a change holds at once.
 */
export function BotAdminsSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(draftOf(settings), (values) => api.put('/bot/settings/bot_admins', values))
  const { values, set, error } = group

  return (
    <SectionCard
      form={group}
      title="مدیریت فروشگاه از ربات"
      description={
        <>
          مدیران ربات در چت خصوصی ربات، با دکمه «⚙️ مدیریت» یا <bdi dir="ltr">/admin</bdi>، کارهای روزانه فروشگاه را با منوهای ربات انجام می‌دهند: داشبورد، رسیدها و پرداخت‌ها، سفارش‌ها، سرویس‌ها،
          کاربران، تیکت‌ها، نظرات، زیرمجموعه‌گیری و ارسال‌های همگانی. تنظیمات فروشگاه، روش‌های پرداخت، ربات و اینکه چه کسی مدیر است با مدیران نیست. مالک و نماینده، هر کدام در ربات خودش، همیشه همه
          بخش‌های پنل خود را در ربات دارند و این گزینه‌ها فقط مدیران ربات را محدود می‌کند.
        </>
      }
    >
      <Callout tone="info" icon={Info}>
        مدیران ربات همان کاربرانی هستند که نقش مدیر دارند؛ آن‌ها را در{' '}
        <TextLink inline to="/users?role=admin">
          لیست کاربران
        </TextLink>{' '}
        ببینید و از منوی هر کاربر، مدیر ربات کنید یا مدیریت را بردارید.
      </Callout>
      <Callout tone="warning" icon={TriangleAlert}>
        هر مدیر فقط درباره حساب خودش محدود است: پرداخت، کیف پول، سرویس و نظر خودش با مدیر دیگری است و حساب مدیران و نماینده‌ها با مدیران نیست. ولی دو مدیر این کارها را برای هم انجام می‌دهند؛ پس نقش
        مدیر را فقط به کسانی بدهید که به آن‌ها اعتماد دارید.
      </Callout>

      <SwitchRow
        label="کار مدیران از ربات"
        hint={
          <>
            خاموش باشد، مدیران ربات دکمه «⚙️ مدیریت» را ندارند و ربات جز <bdi dir="ltr">/broadcast</bdi> و <bdi dir="ltr">/emoji</bdi>، که مثل قبل دارند، کار مدیریتی از آن‌ها نمی‌پذیرد؛ کارشان در گروه
            گزارش‌ها مثل قبل است.
          </>
        }
        checked={values.bot_admins_enabled}
        onCheckedChange={(checked) => set('bot_admins_enabled', checked)}
        error={error('bot_admins_enabled')}
      />

      <FieldGroup label="کارهای بیشتر" description="این کارها فقط وقتی از ربات انجام می‌شود که روشنشان کنید؛ کارهای روزانه همیشه با مدیران است." className="gap-3">
        {GRANTS.map((grant) => (
          <SwitchRow key={grant.field} label={grant.label} hint={grant.hint} checked={values[grant.field]} onCheckedChange={(checked) => set(grant.field, checked)} error={error(grant.field)} />
        ))}
      </FieldGroup>
    </SectionCard>
  )
}
