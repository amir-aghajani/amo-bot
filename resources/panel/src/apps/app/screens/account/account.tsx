import { BookOpen, Bot, Crown, Globe, Headset, Mail, MessageSquareQuote, Send, ShieldCheck } from 'lucide-react'
import { appBuild } from '@/apps/app/config'
import { useAccount, useShop } from '@/apps/app/lib/session'
import { PhoneSection } from '@/apps/app/screens/account/parts/phone'
import { ProfileCard } from '@/apps/app/screens/account/parts/profile-card'
import { clubOpen, reviewsOpen } from '@/apps/app/screens/home/parts/programs'
import { openAddress, openTelegramLink } from '@/apps/app/telegram/webapp'
import { Screen } from '@/apps/app/ui/screen'
import { Row, Section } from '@/apps/app/ui/section'
import { ErrorView, LoadingBlocks } from '@/apps/app/ui/states'
import { Badge } from '@/apps/app/ui/visuals'
import { formatDate } from '@/lib/format'

/*
 * «حساب من», the app's last tab: who the customer is (their card, their phone), the ways their account signs in — this
 * Telegram account always, an email to add here, Google only from the shop's website —, the security settings, the
 * app's other places, how to reach the shop, and which build of the app this is. There is no sign-out: Telegram signs
 * the customer in each time the app opens.
 */

/** The build's moment in the shop's calendar, or as it is when it reads as none. */
function buildWords(stamp: string): string {
  return Number.isNaN(new Date(stamp).getTime()) ? stamp : formatDate(stamp)
}

export default function AccountScreen() {
  const account = useAccount()
  const shop = useShop()

  if (account.isError || shop.isError) {
    return (
      <Screen title="حساب من" bottom="tabs">
        <ErrorView
          error={account.error ?? shop.error}
          retry={() => {
            void account.refetch()
            void shop.refetch()
          }}
        />
      </Screen>
    )
  }
  if (account.data === undefined || shop.data === undefined) {
    return (
      <Screen title="حساب من" bottom="tabs">
        <LoadingBlocks count={4} />
      </Screen>
    )
  }

  const { customer, loyalty } = account.data
  const { shop: about, support } = shop.data
  const bot = about.bot
  const linked = <Badge tone="success">وصل است</Badge>

  return (
    <Screen title="حساب من" bottom="tabs">
      <ProfileCard customer={customer} tier={about.programs.loyalty ? (loyalty.tier?.name ?? null) : null} />

      <PhoneSection phone={customer.phone} required={about.phone_required} botUrl={bot?.url ?? null} />

      <Section title="روش‌های ورود" footer="حساب شما در ربات، مینی‌اپ و وب‌سایت فروشگاه یکی است.">
        <Row
          icon={Send}
          tint="#2aabee"
          title="تلگرام"
          subtitle={customer.telegram?.username ? <span className="ltr">@{customer.telegram.username}</span> : 'همین حساب تلگرام'}
          value={customer.telegram !== null ? linked : undefined}
        />
        {customer.email !== null ? (
          <Row icon={Mail} tint="#ff9500" title="ایمیل" subtitle={<span className="ltr">{customer.email}</span>} value={linked} />
        ) : (
          <Row icon={Mail} tint="#ff9500" title="افزودن ایمیل" subtitle="برای ورود از وب‌سایت فروشگاه" to="/account/email" />
        )}
        <Row
          icon={Globe}
          tint="#4285f4"
          title="Google"
          subtitle={customer.google ? 'از وب‌سایت فروشگاه وصل شده است' : 'از وب‌سایت فروشگاه اضافه می‌شود'}
          value={customer.google ? linked : <Badge tone="hint">وصل نیست</Badge>}
        />
      </Section>

      <Section>
        <Row icon={ShieldCheck} tint="#30b350" title="امنیت" subtitle="تایید با اثر انگشت، رمز عبور و دستگاه‌ها" to="/account/security" />
      </Section>

      <Section>
        {clubOpen(shop.data) && <Row icon={Crown} tint="#ff2d55" title="باشگاه مشتریان" to="/club" />}
        <Row icon={Headset} tint="#1fa5e0" title="پشتیبانی" subtitle="تیکت‌های شما" to="/support" />
        <Row icon={BookOpen} tint="#af52de" title="آموزش" to="/tutorial" />
        {reviewsOpen(shop.data) && <Row icon={MessageSquareQuote} tint="#e6a100" title="نظرات مشتریان" to="/reviews" />}
      </Section>

      {(support !== null || bot !== null) && (
        <Section title="ارتباط با فروشگاه">
          {support !== null && (
            <Row
              icon={Headset}
              tint="#5856d6"
              title="پیام به پشتیبانی"
              subtitle={<span className="ltr">{support.url.replace(/^(https:\/\/|tel:)/, '')}</span>}
              onClick={() => openAddress(support.url)}
            />
          )}
          {bot !== null && <Row icon={Bot} tint="#2aabee" title="ربات فروشگاه" subtitle={<span className="ltr">@{bot.username}</span>} onClick={() => openTelegramLink(bot.url)} />}
        </Section>
      )}

      <p className="text-hint pb-2 text-center text-caption">{`نسخه برنامه: ${buildWords(appBuild)}`}</p>
    </Screen>
  )
}
