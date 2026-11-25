import type { ReactNode } from 'react'
import { Ban, CalendarClock, TrendingDown, type LucideIcon } from 'lucide-react'
import { Callout } from '@/components/callout'
import { Fact } from '@/components/fact-list'
import { InfoBadge } from '@/components/info-tip'
import type { AccountResponse, AgencyStanding } from '@/lib/api-types'
import { formatDate, formatMoney, formatNumber, timeLeft } from '@/lib/format'
import { SELLING_SUSPENSION } from '@/lib/statuses'

/*
 * An agent's terms of selling (the server's `standing`): their agency's end and their monthly minimum purchase, each
 * warned of `warn_days` ahead, and a suspension of their shop's selling — never of its bot. What the agent's panel and the
 * owner's panel (the agents page, the agent's shop opened in it) show of them.
 */

const DAY_MS = 86_400_000

/** What of an agent's terms needs an eye now (standingConcern). */
type Concern = 'suspended' | 'expiring' | 'short'

/**
 * What a shop whose selling is suspended takes no more of — every new order but its agent's own traffic (the server's
 * SellingSuspendedException) —, in the one phrase each word of it uses: «… تازه انجام نمی‌شود».
 */
export const SUSPENDED_SALES = 'خرید، تمدید، حجم، زمان و کاربر اضافه، شارژ کیف پول و اکانت تست'

/** Whole days until a moment, a part of one counting as one; 0 once it passed. */
function daysUntil(iso: string): number {
  return Math.max(0, Math.ceil((new Date(iso).getTime() - Date.now()) / DAY_MS))
}

/** Whether the month's purchases fall short of the minimum while the month is judged. */
function minimumShort(standing: AgencyStanding): boolean {
  return standing.monthly_minimum !== null && standing.month.judged && Number(standing.month.purchased) < Number(standing.monthly_minimum)
}

/** What needs the agent's eye now: a suspension, an end within the warned days, a judged month short near its end; null for nothing. */
export function standingConcern(standing: AgencyStanding | null | undefined): Concern | null {
  if (!standing) return null
  if (standing.suspension) return 'suspended'
  if (standing.expires_at !== null && daysUntil(standing.expires_at) <= standing.warn_days) return 'expiring'
  if (minimumShort(standing) && daysUntil(standing.month.ends_at) <= standing.warn_days) return 'short'
  return null
}

/** «خرید حجم مهر ۱۴۰۵: ۲۵۰٬۰۰۰ از ۵۰۰٬۰۰۰ تومان» — this month's purchases against the minimum; '' without one. */
export function monthWords(standing: AgencyStanding): string {
  if (standing.monthly_minimum === null) return ''
  return `${formatMoney(standing.month.purchased)} از ${formatMoney(standing.monthly_minimum)}`
}

/** An agency's end now as a renewal is read by it: «بدون پایان», its day and the time left, or its day and that it passed. */
export function agencyEndWords(expiresAt: string | null): string {
  if (expiresAt === null) return 'بدون پایان'
  return `${formatDate(expiresAt)} (${new Date(expiresAt).getTime() <= Date.now() ? 'گذشته است' : timeLeft(expiresAt)})`
}

/** What SellingNotice says of renewing when nothing else is said: where the agent asks for it. */
const RENEW_IN_ACCOUNT = 'برای تمدید، در «حساب نمایندگی» «درخواست تمدید نمایندگی» را بزنید.'

/**
 * What the agent's notice says of renewing an end passed or near (SellingNotice's `renew`), by their account: that their
 * request waits — wherever it is said, as no button shows then —, or, on the account page itself (`here`), that the
 * button is in its «شرایط فروش»; undefined for the notice's own word, which sends them to «حساب نمایندگی».
 */
export function renewWords(account: Pick<AccountResponse['account'], 'renewal' | 'actions'>, here = false): string | undefined {
  if (account.renewal?.status === 'pending') return 'درخواست تمدید شما در انتظار بررسی است.'
  return here && account.actions.request_renewal ? 'برای تمدید، «درخواست تمدید نمایندگی» را در بخش «شرایط فروش» همین صفحه بزنید.' : undefined
}

/**
 * The agent's notice (their dashboard's attention card, and their account, where the shell's strip leads): why their bot
 * sells nothing now and what lifts it, or what is near — nothing while all is well. `renew` is what it says of renewing
 * an end passed or near, in place of sending them to their account's «درخواست تمدید نمایندگی» (renewWords).
 */
export function SellingNotice({ standing, renew }: { standing: AgencyStanding | null | undefined; renew?: string }) {
  const concern = standingConcern(standing)
  if (!standing || concern === null) return null

  if (concern === 'suspended' && standing.suspension) {
    const reason = standing.suspension.reason
    return (
      <Callout tone="danger" icon={Ban}>
        <span className="font-medium">فروش ربات شما متوقف است: {SELLING_SUSPENSION[reason].label}.</span> ربات، پنل و سرویس‌های فعلی مشتری‌ها کار می‌کنند، ولی {SUSPENDED_SALES} تازه انجام نمی‌شود.{' '}
        {reason === 'minimum'
          ? `با خرید حجم در ربات اصلی تا رسیدن خرید ${standing.month.name} به کف (${monthWords(standing)})، فروش خودکار باز می‌شود.`
          : reason === 'expired'
            ? (renew ?? RENEW_IN_ACCOUNT)
            : 'فقط پشتیبانی فروشگاه اصلی آن را دوباره باز می‌کند.'}
      </Callout>
    )
  }
  if (concern === 'expiring' && standing.expires_at !== null) {
    return (
      <Callout tone="warning" icon={CalendarClock}>
        <span className="font-medium">
          نمایندگی شما {formatDate(standing.expires_at)} به پایان می‌رسد ({timeLeft(standing.expires_at)}).
        </span>{' '}
        از آن به بعد ربات شما فروش تازه‌ای ندارد؛ {renew ?? RENEW_IN_ACCOUNT}
      </Callout>
    )
  }
  return (
    <Callout tone="warning" icon={TrendingDown}>
      <span className="font-medium">
        تا پایان {standing.month.name} {formatNumber(daysUntil(standing.month.ends_at))} روز مانده و خرید حجم این ماه {monthWords(standing)} است.
      </span>{' '}
      اگر تا پایان ماه به کف نرسد، فروش ربات شما متوقف می‌شود.
    </Callout>
  )
}

/**
 * The owner's word on an agent's terms, said of the agent — a badge, and what it means —: their selling stopped (and
 * why), their end near, or their month short; null while all is well. Their row on the agents list, and their shop's
 * dashboard in the owner's panel. `remedy` is what to do about it from the row (its «سطح، اعتبار و شرایط فروش»), which
 * the dashboard's notice says its own way.
 */
function ownerWord(standing: AgencyStanding): { concern: Concern; badge: string; info: string; remedy?: string } | null {
  const concern = standingConcern(standing)
  if (concern === 'suspended' && standing.suspension) {
    return { concern, badge: 'فروش متوقف', info: `${SELLING_SUSPENSION[standing.suspension.reason].label}. ربات، پنل و سرویس‌های فعلی مشتری‌هایش کار می‌کنند.` }
  }
  if (concern === 'expiring' && standing.expires_at !== null) {
    return {
      concern,
      badge: `پایان: ${timeLeft(standing.expires_at)}`,
      info: `نمایندگی ${formatDate(standing.expires_at)} به پایان می‌رسد؛ بعد از آن فروش رباتش متوقف می‌شود.`,
      remedy: 'با «سطح، اعتبار و شرایط فروش» تمدیدش کنید.',
    }
  }
  if (concern === 'short') {
    return { concern, badge: 'کف ماهانه نرسیده', info: `خرید حجم ${standing.month.name}: ${monthWords(standing)}. اگر تا پایان ماه به کف نرسد، فروش رباتش متوقف می‌شود.` }
  }
  return null
}

/** The owner's word on an agent's row (ownerWord): pressed, it says why and what to do there; nothing while all is well. */
export function StandingBadge({ standing }: { standing: AgencyStanding }) {
  const word = ownerWord(standing)
  if (word === null) return null

  return (
    <span className="mt-1 flex">
      <InfoBadge variant={word.concern === 'suspended' ? 'danger' : 'warning'} info={word.remedy ? `${word.info} ${word.remedy}` : word.info}>
        {word.badge}
      </InfoBadge>
    </span>
  )
}

/** The owner's notice's first words and mark, by concern: of the agent whose shop is open in their panel. */
const OWNER_LEADS: Record<Concern, { lead: string; icon: LucideIcon }> = {
  suspended: { lead: 'فروش ربات این نماینده متوقف است:', icon: Ban },
  expiring: { lead: 'پایان نمایندگی این نماینده نزدیک است.', icon: CalendarClock },
  short: { lead: 'خرید حجم این نماینده در این ماه هنوز به کف نرسیده است.', icon: TrendingDown },
}

/**
 * The owner's notice in an agent's shop opened in their panel (its dashboard's attention card): the agent's terms in
 * their row's words (ownerWord), and where to see to them, the panel's way (`help`) — asked once: the row's own remedy
 * gives way to it. Nothing while all is well.
 */
export function OwnerSellingNotice({ standing, help }: { standing: AgencyStanding; help?: ReactNode }) {
  const word = ownerWord(standing)
  if (word === null) return null
  const { lead, icon } = OWNER_LEADS[word.concern]

  return (
    <Callout tone={word.concern === 'suspended' ? 'danger' : 'warning'} icon={icon}>
      <span className="font-medium">{lead}</span> {word.info}
      {help ? <> {help}</> : word.remedy && <> {word.remedy}</>}
    </Callout>
  )
}

/**
 * The terms as facts (inside a FactList): the end, this month's purchases against the minimum, and the selling.
 * `renewal` goes under the end: the agent's own word on renewing it (their account).
 */
export function StandingFacts({ standing, renewal }: { standing: AgencyStanding; renewal?: ReactNode }) {
  const end = standing.expires_at === null ? 'بدون پایان' : `${formatDate(standing.expires_at)} (${timeLeft(standing.expires_at)})`

  return (
    <>
      <Fact label="پایان نمایندگی">
        {renewal ? (
          <div className="grid justify-items-start gap-2">
            <span>{end}</span>
            {renewal}
          </div>
        ) : (
          end
        )}
      </Fact>
      <Fact label={`خرید حجم ${standing.month.name}`}>
        {standing.monthly_minimum === null ? `${formatMoney(standing.month.purchased)} · بدون کف ماهانه` : `${monthWords(standing)}${standing.month.judged ? '' : ' (این ماه سنجیده نمی‌شود)'}`}
      </Fact>
      <Fact label="فروش">{standing.suspension ? `متوقف: ${SELLING_SUSPENSION[standing.suspension.reason].label}` : 'باز'}</Fact>
    </>
  )
}
