import { useQuery } from '@tanstack/react-query'
import { Gift, HandCoins, Package, RefreshCw, UsersRound } from 'lucide-react'
import { keys } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { store } from '@/apps/app/lib/store'
import { tap } from '@/apps/app/telegram/haptics'
import { shareToChat } from '@/apps/app/telegram/webapp'
import { MainAction } from '@/apps/app/ui/button'
import { CopyValue } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Row, Section } from '@/apps/app/ui/section'
import { ErrorView, LoadingBlocks } from '@/apps/app/ui/states'
import { IconTile } from '@/apps/app/ui/visuals'
import type { StoreReferralResponse } from '@/lib/api-types'
import { formatMoney, formatNumber, formatPercent } from '@/lib/format'

/*
 * «دعوت دوستان»: the customer's invite link — copied, or sent to a chat through Telegram's share sheet — and its code;
 * the program's terms as the shop set them (the share of each payment, renewals', the first payment alone, the start
 * gift, the reward for every few buyers); and what their invitations brought them so far.
 */

/** A number the program counts: one of the stats card's equal columns, the number over its label. */
function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex min-w-0 flex-1 basis-0 flex-col items-center gap-1 px-2 py-4 text-center">
      <span className="text-heading font-extrabold">{value}</span>
      <span className="text-hint text-caption">{label}</span>
    </div>
  )
}

/** The hairline between two of the stats card's columns. */
function StatRule() {
  return <span aria-hidden className="bg-separator my-4 w-px shrink-0 scale-x-50" />
}

export default function ReferralScreen() {
  const { data: shop } = useShop()
  const referral = useQuery({ queryKey: keys.referral, queryFn: () => store.get<StoreReferralResponse>('/referral') })

  if (referral.isError) {
    return (
      <Screen back title="دعوت دوستان">
        <ErrorView error={referral.error} retry={() => void referral.refetch()} />
      </Screen>
    )
  }
  if (referral.data === undefined) {
    return (
      <Screen back title="دعوت دوستان">
        <LoadingBlocks count={3} />
      </Screen>
    )
  }

  const program = referral.data
  const title = shop?.app?.title ?? shop?.shop.name ?? 'فروشگاه'
  const gift = program.start_gift !== null && Number(program.start_gift.invitee) > 0 ? program.start_gift.invitee : null
  const message = gift === null ? `با این لینک در ${title} عضو شو.` : `با این لینک در ${title} عضو شو و ${formatMoney(gift)} هدیه بگیر.`
  const link = program.bot_link

  return (
    <Screen back title="دعوت دوستان" bottom={link === null ? 'none' : 'action'}>
      <section aria-label="برنامه دعوت" className="card animate-rise relative isolate flex flex-col items-center gap-2 overflow-hidden p-5 text-center">
        <span aria-hidden className="absolute -top-20 left-1/2 -z-10 size-56 -translate-x-1/2 rounded-full opacity-25 blur-3xl" style={{ background: 'var(--app-accent)' }} />
        <IconTile icon={UsersRound} size="2xl" className="mb-2" />
        <h2 className="text-title font-bold">دوستانتان را دعوت کنید</h2>
        <p className="text-hint text-footnote">
          {program.enabled
            ? `از ${program.first_only ? 'اولین پرداخت' : 'هر پرداخت'} دوستانی که با لینک شما بیایند، ${formatPercent(program.rate)} به کیف پول شما می‌آید.`
            : 'برنامه دعوت فعلا فعال نیست؛ تا وقتی خاموش است، پرداخت‌های دوستانتان سهمی برای شما ندارد.'}
        </p>
      </section>

      <div className="card flex items-stretch">
        <Stat label="دعوت‌شده" value={formatNumber(program.invited)} />
        <StatRule />
        <Stat label="درآمد (تومان)" value={formatNumber(Math.floor(Number(program.earned)))} />
        <StatRule />
        <Stat label="جایزه دعوت" value={formatNumber(program.rewards)} />
      </div>

      <Section
        title="لینک دعوت شما"
        footer={link === null ? 'لینک ربات هنوز آماده نیست؛ کد دعوت را برای دوستانتان بفرستید.' : 'دوستانتان با این لینک وارد ربات فروشگاه می‌شوند و دعوت به نام شما ثبت می‌شود.'}
      >
        <div className="flex flex-col gap-3 p-4">
          {link !== null && <CopyValue label="لینک دعوت" value={link} done="لینک دعوت کپی شد" />}
          <CopyValue label="کد دعوت" value={program.code} done="کد دعوت کپی شد" />
        </div>
      </Section>

      {program.enabled && (
        <Section title="شرایط" footer="سهم دعوت از پرداخت‌هایی است که پول تازه به فروشگاه می‌آورد، نه پرداخت از کیف پول.">
          <Row icon={HandCoins} title="سهم شما از پرداخت‌ها" subtitle={program.first_only ? 'فقط اولین پرداخت هر دوست' : 'از هر پرداخت دوستان شما'} value={formatPercent(program.rate)} />
          {program.renewal_rate !== program.rate && <Row icon={RefreshCw} title="سهم از تمدیدها" value={program.renewal_rate > 0 ? formatPercent(program.renewal_rate) : 'ندارد'} />}
          {program.start_gift !== null && (
            <Row
              icon={Gift}
              title="هدیه شروع"
              subtitle={`${program.start_gift.trigger === 'join' ? 'وقتی دوستتان عضو شود' : 'با اولین خرید دوستتان'}: ${formatMoney(program.start_gift.referrer)} برای شما و ${formatMoney(program.start_gift.invitee)} برای او`}
            />
          )}
          {program.reward !== null && <Row icon={Package} title="جایزه دعوت" subtitle={`هر ${formatNumber(program.reward.every)} دوست خریدار: یک سرویس «${program.reward.plan.name}» رایگان`} />}
        </Section>
      )}

      {link !== null && (
        <MainAction
          text="اشتراک‌گذاری در تلگرام"
          onClick={() => {
            tap('medium')
            shareToChat(link, message)
          }}
        />
      )}
    </Screen>
  )
}
