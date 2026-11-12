import { ArrowDownToLine, CircleAlert, UsersRound } from 'lucide-react'
import { Fact, Section } from '@/apps/app/ui/section'
import { Notice } from '@/apps/app/ui/states'
import { IconTile } from '@/apps/app/ui/visuals'
import type { StoreMergeOfferResponse } from '@/lib/api-types'
import { formatDate, formatMoney, formatNumber } from '@/lib/format'

/*
 * The merge offer of an email that is another account's of the shop: that account as the customer can tell it is theirs
 * (its name, since when, its services, orders and balance, how it signs in), and what making the two one does — the
 * older one stays and takes everything of the other, the email with the password just chosen among it —, said plainly
 * before they agree.
 */

type Offer = StoreMergeOfferResponse['merge']

/** The ways the other account signs in, in words. */
function waysOf(account: Offer['account']): string {
  const ways = [account.telegram && 'تلگرام', account.email && 'ایمیل', account.google && 'Google'].filter(Boolean)

  return ways.length === 0 ? '—' : ways.join('، ')
}

export function MergeOffer({ offer, email }: { offer: Offer; email: string }) {
  const { account, keeps } = offer

  return (
    <>
      <div className="flex flex-col items-center gap-3 px-4 pt-2 text-center">
        <IconTile icon={UsersRound} size="2xl" look="soft" round />
        <p className="text-heading font-bold">این ایمیل مال حساب دیگری است</p>
        <p className="text-hint text-footnote">
          <span className="ltr text-text">{email}</span> به حساب دیگری در همین فروشگاه وصل است. اگر آن حساب هم مال شماست، می‌توانید دو حساب را یکی کنید.
        </p>
      </div>

      <Section title="حساب دیگر">
        <Fact label="نام">{account.name ?? '—'}</Fact>
        <Fact label="عضو از">{formatDate(account.created_at, { dateStyle: 'long' })}</Fact>
        <Fact label="سرویس‌های فعال">{formatNumber(account.services)}</Fact>
        <Fact label="سفارش‌ها">{formatNumber(account.orders)}</Fact>
        <Fact label="موجودی کیف پول">{formatMoney(account.balance)}</Fact>
        <Fact label="روش‌های ورود">{waysOf(account)}</Fact>
      </Section>

      <Section title="یکی کردن دو حساب یعنی">
        <ul className="flex flex-col gap-3 p-4 text-footnote">
          <li className="flex gap-3">
            <ArrowDownToLine aria-hidden className="text-accent-text mt-0.5 size-4 shrink-0" />
            <span>
              {keeps === 'this'
                ? 'حساب فعلی شما که قدیمی‌تر است می‌ماند و حساب دیگر در آن ادغام می‌شود.'
                : 'حساب دیگر که قدیمی‌تر است می‌ماند و حساب فعلی شما در آن ادغام می‌شود؛ شما با همان حساب وارد می‌مانید و شماره مشتری شما عوض می‌شود.'}
            </span>
          </li>
          <li className="flex gap-3">
            <ArrowDownToLine aria-hidden className="text-accent-text mt-0.5 size-4 shrink-0" />
            <span>سفارش‌ها و پرداخت‌ها، سرویس‌ها، موجودی کیف پول (جمع دو موجودی)، تیکت‌ها، اعلان‌ها، نظرها و دوستان دعوت‌شده همه به حسابی که می‌ماند منتقل می‌شوند.</span>
          </li>
          <li className="flex gap-3">
            <ArrowDownToLine aria-hidden className="text-accent-text mt-0.5 size-4 shrink-0" />
            <span>روش‌های ورود، نام و شماره تلفن هر حساب، جاهای خالی حساب باقی‌مانده را پر می‌کنند و این ایمیل با رمزی که انتخاب کردید به آن اضافه می‌شود.</span>
          </li>
          <li className="flex gap-3">
            <CircleAlert aria-hidden className="mt-0.5 size-4 shrink-0 text-warning" />
            <span>رمز انتخابی شما رمز حساب باقی‌مانده می‌شود و دستگاه‌های دیگر آن حساب از آن خارج می‌شوند.</span>
          </li>
        </ul>
      </Section>

      <Notice tone="warning">یکی کردن دو حساب برگشت ندارد. اگر آن حساب مال شما نیست، انصراف بدهید؛ چیزی تغییر نمی‌کند.</Notice>
    </>
  )
}
