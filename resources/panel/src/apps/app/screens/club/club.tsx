import type { ComponentType } from 'react'
import { Coins, Crown, FerrisWheel, Gift, Ticket, UsersRound } from 'lucide-react'
import { Link } from 'react-router'
import { useAccount, useShop } from '@/apps/app/lib/session'
import { tap } from '@/apps/app/telegram/haptics'
import { Screen } from '@/apps/app/ui/screen'
import { Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, LoadingBlocks } from '@/apps/app/ui/states'
import { ACCENT_FILL, Badge, Bar, IconTile } from '@/apps/app/ui/visuals'
import type { LoyaltyStanding } from '@/lib/api-types'
import { formatMoney, formatPercent } from '@/lib/format'

/*
 * «باشگاه مشتریان»: what the shop gives back to those who buy from it, each part only while it runs — the customer's
 * loyalty tier with its perks and how far the next one is, the cashback every delivered order earns, and the ways in to
 * the lucky wheel, the nightly draw and the referral program.
 */

/** The customer's tier: its name and perks, a bar to the next tier and what is left to reach it. */
function LoyaltyCard({ loyalty }: { loyalty: LoyaltyStanding }) {
  const { tier, next } = loyalty
  const progress = next === null ? 1 : Math.min(1, Number(loyalty.spent) / Math.max(1, Number(next.min_spent)))

  return (
    <section aria-label="سطح وفاداری" className="card animate-rise relative isolate flex flex-col gap-4 overflow-hidden p-5">
      <span aria-hidden className="absolute -end-16 -top-16 -z-10 size-44 rounded-full opacity-25 blur-2xl" style={{ background: 'var(--app-accent)' }} />
      <div className="flex items-center gap-3">
        <IconTile icon={Crown} size="xl" />
        <div className="flex min-w-0 flex-1 flex-col">
          <p className="text-hint text-footnote">سطح شما</p>
          <p className="truncate text-title font-bold">{tier?.name ?? 'هنوز بدون سطح'}</p>
        </div>
      </div>

      {tier !== null && (tier.discount_percent > 0 || tier.cashback_percent > 0) && (
        <div className="flex flex-wrap gap-2">
          {tier.discount_percent > 0 && <Badge tone="accent">{`${formatPercent(tier.discount_percent)} تخفیف روی خریدها`}</Badge>}
          {tier.cashback_percent > 0 && <Badge tone="success">{`${formatPercent(tier.cashback_percent)} کش‌بک اضافه`}</Badge>}
        </div>
      )}

      {(tier !== null || next !== null) && (
        <div className="flex flex-col gap-2">
          <Bar value={progress} />
          <div className="text-hint flex items-center justify-between gap-3 text-caption">
            <span>{`مجموع خرید: ${formatMoney(loyalty.spent)}`}</span>
            {next === null ? <span className="text-accent-text font-semibold">بالاترین سطح باشگاه</span> : <span>{`سطح بعد: ${next.name}`}</span>}
          </div>
          {next !== null && (
            <p className="text-footnote">
              <span className="font-bold">{formatMoney(next.missing)}</span>
              {` تا سطح ${next.name}`}
            </p>
          )}
        </div>
      )}
    </section>
  )
}

interface Tile {
  to: string
  title: string
  text: string
  icon: ComponentType<{ className?: string }>
  /** The tile's colour: a CSS colour. */
  tint: string
}

/** A program's way in: a card with its icon's square (40px) in its colour, its name and a line on what it gives. */
function ProgramTile({ to, title, text, icon, tint }: Tile) {
  return (
    <Link to={to} onClick={() => tap()} className="card press relative isolate flex flex-col gap-3 overflow-hidden p-4">
      <span aria-hidden className="absolute -end-10 -top-10 -z-10 size-28 rounded-full opacity-20 blur-xl" style={{ background: tint }} />
      <IconTile icon={icon} size="lg" color={tint} />
      <span className="flex flex-col gap-0.5">
        <span className="text-body font-bold">{title}</span>
        <span className="text-hint text-footnote">{text}</span>
      </span>
    </Link>
  )
}

export default function ClubScreen() {
  const shopRead = useShop()
  const accountRead = useAccount()
  const shop = shopRead.data
  const account = accountRead.data

  if (shop === undefined || account === undefined) {
    const failure = (shop === undefined ? shopRead.error : null) ?? (account === undefined ? accountRead.error : null)

    return (
      <Screen back title="باشگاه مشتریان">
        {failure !== null ? (
          <ErrorView
            error={failure}
            retry={() => {
              if (shopRead.isError) void shopRead.refetch()
              if (accountRead.isError) void accountRead.refetch()
            }}
          />
        ) : (
          <LoadingBlocks count={3} />
        )}
      </Screen>
    )
  }

  const { programs } = shop.shop
  const tiles: Tile[] = [
    ...(programs.wheel ? [{ to: '/club/wheel', title: 'گردونه شانس', text: 'بچرخانید و جایزه ببرید', icon: FerrisWheel, tint: ACCENT_FILL }] : []),
    ...(programs.lottery ? [{ to: '/club/lottery', title: 'قرعه‌کشی شبانه', text: 'هر شب جایزه برای خریداران', icon: Ticket, tint: 'var(--app-warning)' }] : []),
    ...(shop.referral.enabled ? [{ to: '/club/referral', title: 'دعوت دوستان', text: `${formatPercent(shop.referral.rate)} از پرداخت دوستانتان`, icon: UsersRound, tint: 'var(--app-success)' }] : []),
  ]
  const tierCashback = account.loyalty.tier?.cashback_percent ?? 0

  if (!programs.loyalty && !programs.cashback && tiles.length === 0) {
    return (
      <Screen back title="باشگاه مشتریان">
        <Empty icon={Gift} title="باشگاه مشتریان فعلا برنامه‌ای ندارد" text="به زودی جایزه‌ها و مزایای خرید از فروشگاه اینجا می‌آید." />
      </Screen>
    )
  }

  return (
    <Screen back title="باشگاه مشتریان" subtitle="مزایای خرید از فروشگاه">
      {programs.loyalty && <LoyaltyCard loyalty={account.loyalty} />}

      {programs.cashback && (
        <div className="card flex items-start gap-3 p-4">
          <IconTile icon={Coins} size="lg" color="var(--app-success)" look="soft" />
          <div className="flex min-w-0 flex-1 flex-col gap-0.5">
            <p className="text-heading font-bold">کش‌بک</p>
            <p className="text-hint text-footnote">
              بخشی از مبلغ هر سفارش تحویل‌شده به کیف پول شما برمی‌گردد.
              {programs.loyalty && tierCashback > 0 && ` سطح شما ${formatPercent(tierCashback)} کش‌بک اضافه هم دارد.`}
            </p>
          </div>
        </div>
      )}

      {tiles.length > 0 && (
        <Section title="جایزه‌ها و برنامه‌ها" bare>
          <div className="grid auto-rows-fr grid-cols-2 gap-3">
            {tiles.map((tile) => (
              <ProgramTile key={tile.to} {...tile} />
            ))}
          </div>
        </Section>
      )}
    </Screen>
  )
}
