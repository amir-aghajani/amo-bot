import { useState, type ComponentType } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, Crown, Gift, History, Plus, ReceiptText, Send, Sparkles } from 'lucide-react'
import { Link } from 'react-router'
import { keys } from '@/apps/app/lib/query'
import { useAccount, useShop } from '@/apps/app/lib/session'
import { store } from '@/apps/app/lib/store'
import { BalanceCard } from '@/apps/app/screens/wallet/parts/balance-card'
import { GiftCodeSheet } from '@/apps/app/screens/wallet/parts/gift-code'
import { LedgerLine } from '@/apps/app/screens/wallet/parts/ledger'
import { tap } from '@/apps/app/telegram/haptics'
import { Screen } from '@/apps/app/ui/screen'
import { Section } from '@/apps/app/ui/section'
import { ErrorView, Skeleton } from '@/apps/app/ui/states'
import { IconTile } from '@/apps/app/ui/visuals'
import type { StoreWalletResponse, StoreWalletTransactionsResponse } from '@/lib/api-types'
import { formatMoney } from '@/lib/format'

/*
 * «کیف پول», a tab of its own: the balance on its card, what the customer does with it — a top-up, a transfer to another
 * customer and a gift code while the shop offers them, the history —, the latest lines of its ledger, and the way to
 * the customer club while loyalty or cashback runs.
 */

/** How many of the ledger's latest lines the wallet shows. */
const RECENT = 5

interface Action {
  label: string
  icon: ComponentType<{ className?: string }>
  to?: string
  onClick?: () => void
}

/** A quick action under the card: a round icon and its word, the tiles side by side in equal columns. */
function ActionTile({ label, icon, to, onClick }: Action) {
  const body = (
    <>
      <IconTile icon={icon} size="lg" look="soft" round />
      <span className="max-w-full truncate text-footnote font-medium">{label}</span>
    </>
  )
  const tile = 'card press flex min-w-0 flex-col items-center gap-2 px-1 py-3'

  return to === undefined ? (
    <button
      type="button"
      className={tile}
      onClick={() => {
        tap()
        onClick?.()
      }}
    >
      {body}
    </button>
  ) : (
    <Link to={to} className={tile} onClick={() => tap()}>
      {body}
    </Link>
  )
}

export default function WalletScreen() {
  const [gift, setGift] = useState(false)
  const { data: shop } = useShop()
  const { data: account } = useAccount()
  const wallet = useQuery({ queryKey: keys.wallet, queryFn: () => store.get<StoreWalletResponse>('/wallet') })
  const recent = useQuery({ queryKey: [...keys.transactions, 'recent'], queryFn: () => store.get<StoreWalletTransactionsResponse>('/wallet/transactions') })

  const programs = shop?.shop.programs
  const balance = wallet.data?.balance ?? account?.customer.balance
  const holder = account === undefined ? null : [account.customer.first_name, account.customer.last_name].filter(Boolean).join(' ') || null
  const tier = programs?.loyalty ? (account?.loyalty.tier ?? null) : null
  const next = programs?.loyalty ? (account?.loyalty.next ?? null) : null
  const actions: Action[] = [
    { label: 'شارژ', icon: Plus, to: '/wallet/top-up' },
    ...(programs?.wallet_transfers ? [{ label: 'انتقال', icon: Send, to: '/wallet/transfer' }] : []),
    ...(programs?.gift_codes ? [{ label: 'کد هدیه', icon: Gift, onClick: () => setGift(true) }] : []),
    { label: 'تاریخچه', icon: History, to: '/wallet/history' },
  ]

  return (
    <Screen title="کیف پول" bottom="tabs">
      {balance === undefined ? (
        wallet.isError ? (
          <ErrorView error={wallet.error} retry={() => void wallet.refetch()} />
        ) : (
          <Skeleton className="h-52" />
        )
      ) : (
        <BalanceCard shop={shop?.app?.title ?? shop?.shop.name ?? ''} holder={holder} balance={balance} credit={wallet.data?.credit} spendable={wallet.data?.spendable} tier={tier?.name ?? null} />
      )}

      <nav aria-label="کارهای کیف پول" className="grid auto-cols-fr grid-flow-col gap-2">
        {actions.map((action) => (
          <ActionTile key={action.label} {...action} />
        ))}
      </nav>

      {(programs?.loyalty || programs?.cashback) && (
        <Link to="/club" onClick={() => tap()} className="card press flex items-center gap-3 p-4">
          <IconTile icon={tier !== null ? Crown : Sparkles} size="lg" />
          <span className="flex min-w-0 flex-1 flex-col gap-0.5">
            <span className="text-body font-semibold">{tier !== null ? `سطح ${tier.name}` : 'باشگاه مشتریان'}</span>
            <span className="text-hint text-footnote">
              {next !== null
                ? `${formatMoney(next.missing)} خرید دیگر تا سطح ${next.name}`
                : programs?.cashback
                  ? 'با هر خرید، بخشی از مبلغ به کیف پول شما برمی‌گردد'
                  : 'سطح و مزایای شما در باشگاه مشتریان'}
            </span>
          </span>
          <ChevronLeft aria-hidden className="text-hint size-4.5 shrink-0 opacity-50" />
        </Link>
      )}

      <Section
        title="تراکنش‌های اخیر"
        action={
          <Link to="/wallet/history" className="text-accent-text tap-target relative text-footnote font-semibold">
            همه
          </Link>
        }
      >
        {recent.data === undefined ? (
          recent.isError ? (
            <ErrorView error={recent.error} retry={() => void recent.refetch()} />
          ) : (
            <div className="flex flex-col gap-px" role="status" aria-label="در حال بارگذاری">
              {Array.from({ length: 3 }, (_, index) => (
                <Skeleton key={index} className="h-17.5 rounded-none" />
              ))}
            </div>
          )
        ) : recent.data.transactions.length === 0 ? (
          <div className="flex flex-col items-center gap-3 px-6 py-8 text-center">
            <IconTile icon={ReceiptText} size="xl" color="var(--app-hint)" look="soft" round />
            <p className="text-hint text-footnote">هنوز تراکنشی در کیف پول شما ثبت نشده است.</p>
          </div>
        ) : (
          recent.data.transactions.slice(0, RECENT).map((line) => <LedgerLine key={line.id} line={line} />)
        )}
      </Section>

      <GiftCodeSheet open={gift} onClose={() => setGift(false)} />
    </Screen>
  )
}
