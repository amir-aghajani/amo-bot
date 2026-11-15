import { useId, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { MessageSquareText, Search } from 'lucide-react'
import { DetailFooter, DetailModal } from '@/components/detail-modal'
import { EmptyState } from '@/components/empty-state'
import { Fact, FactList } from '@/components/fact-list'
import { Dash, ListView, PhoneFacts, RowTitleButton } from '@/components/list-view'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { depositSettingsQuery } from '@/components/payments/deposits/queries'
import { ProgramOff } from '@/components/program-off'
import { SearchBox } from '@/components/search-box'
import { StatusBadge, statusTabs } from '@/components/status-badge'
import { searchLink, TextLink } from '@/components/text-link'
import { TimeAgo } from '@/components/time-ago'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { CustomerFacts, UserIdentity, userPage } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { DepositRow, DepositsResponse, DepositStatus } from '@/lib/api-types'
import { isolateIds } from '@/lib/direction'
import { formatDate, formatMoney, formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { DEPOSIT_STATUS } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'
import { cn } from '@/lib/utils'

/** The list's tabs after «همه»: what waits on support first — a deposit no payment asks, one several do —, then the rest. */
const TABS: DepositStatus[] = ['unmatched', 'ambiguous', 'matched', 'ignored', 'test']

/** What a tab with nothing in it says. */
const NONE: Record<DepositStatus, string> = {
  received: 'پیامکی در حال بررسی نیست.',
  unmatched: 'فعلا واریزی بدون پرداخت نیست.',
  ambiguous: 'فعلا واریزی با چند پرداخت هم‌مبلغ نیست.',
  matched: 'هنوز واریزی پرداختی را تایید نکرده است.',
  ignored: 'پیامک نادیده‌ای نیست.',
  test: 'هنوز پیام آزمایشی نرسیده است.',
}

/** When the phone got it — else when the shop did. */
const arrived = (deposit: DepositRow) => deposit.received_at ?? deposit.created_at

/**
 * How a deposit is spoken of by where it came from: ForApp forwards the bank's SMS as it came; Rikhtam sends no SMS, only
 * what it read of one (the sum, the account, the time), which the shop keeps as a line in its place.
 */
const FORAPP = { said: 'پیامک واریز بانک، همان‌طور که فوراپ فرستاد.', body: 'متن پیامک', open: 'پیامک' }
const SOURCES: Partial<Record<string, typeof FORAPP>> = {
  forapp: FORAPP,
  rikhtam: { said: 'واریزی که ریختم از پیامک بانک خبر داد؛ ریختم خود پیامک را نمی‌فرستد.', body: 'آنچه ریختم فرستاد', open: 'جزئیات' },
}

/** How `deposit` is spoken of: its source's words, ForApp's for a source the panel does not know. */
const sourceOf = (deposit: DepositRow) => SOURCES[deposit.source] ?? FORAPP

/**
 * «واریزهای پیامکی»: the bank deposits the phone app forwarded or Rikhtam told, newest first, by what came of each (the
 * tabs) or searched by sum or words — the card payment each approved and its customer, or why none; a row opens with the
 * SMS as it came (Rikhtam's: what it sent).
 */
export function DepositsList() {
  const tabs = useId()
  const list = usePagedList({
    queryKey: queryKeys.deposits,
    read: (query) => api.get<DepositsResponse>('/deposits', query),
    list: 'deposits',
    statuses: ['', ...TABS],
    address: '/payments/deposits',
  })
  const [open, setOpen] = useState<DepositRow | null>(null)

  return (
    <>
      <PageTabs id={tabs} value={list.filter} tabs={statusTabs(DEPOSIT_STATUS, TABS)} onChange={list.setFilter} aria-label="وضعیت واریز" />

      <div {...tabPanel(tabs, list.filter)} className="grid gap-4">
        <SearchBox value={list.typed} onChange={list.setTyped} placeholder="مبلغ به تومان یا ریال، یا متن پیامک" aria-label="جستجوی واریز" />

        <ListView
          list={list}
          noun="واریزها"
          unit="واریز"
          skeletonRows={6}
          empty={<EmptyState framed icon={MessageSquareText} title="هنوز پیامکی نرسیده است" description="وقتی تایید خودکار واریز روشن و فوراپ یا ریختم وصل باشد، هر پیامک واریز بانک این‌جا می‌آید." />}
          noMatch={<EmptyState framed icon={Search} title="واریزی پیدا نشد" description={list.narrowed || list.filter === '' ? 'با این جستجو چیزی نیست.' : NONE[list.filter]} />}
        >
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>واریز</TableHead>
                <TableHead className="hidden md:table-cell">بانک</TableHead>
                <TableHead className="hidden md:table-cell">وضعیت</TableHead>
                <TableHead className="hidden lg:table-cell">نتیجه</TableHead>
                <TableHead className="hidden sm:table-cell">زمان</TableHead>
                <TableHead className="md:w-24">
                  <span className="sr-only">عملیات</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.rows.map((deposit) => (
                <TableRow key={deposit.id} className={cn((deposit.status === 'ignored' || deposit.status === 'test') && 'text-muted-foreground')}>
                  <TableCell>
                    <div className="grid">
                      <RowTitleButton onClick={() => setOpen(deposit)}>
                        <Amount deposit={deposit} />
                      </RowTitleButton>
                      {deposit.amount_rial !== null && <span className="text-footnote text-muted-foreground tabular">{formatNumber(deposit.amount_rial)} ریال</span>}
                    </div>
                    <PhoneFacts>
                      <StatusBadge status={DEPOSIT_STATUS[deposit.status]} />
                      {deposit.payment && <PaymentLink payment={deposit.payment} />}
                    </PhoneFacts>
                  </TableCell>
                  <TableCell className="hidden md:table-cell">
                    <Bank deposit={deposit} />
                  </TableCell>
                  <TableCell className="hidden md:table-cell">
                    <StatusBadge status={DEPOSIT_STATUS[deposit.status]} />
                  </TableCell>
                  <TableCell className="hidden max-w-80 whitespace-normal lg:table-cell">
                    <Outcome deposit={deposit} />
                  </TableCell>
                  <TableCell className="hidden text-muted-foreground sm:table-cell">
                    <TimeAgo at={arrived(deposit)} />
                  </TableCell>
                  <TableCell>
                    <div className="flex justify-end">
                      <Button size="sm" variant="secondary" onClick={() => setOpen(deposit)}>
                        {sourceOf(deposit).open}
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </ListView>
      </div>

      <DetailModal subject={open} title={(deposit) => <Amount deposit={deposit} />} description={(deposit) => sourceOf(deposit).said} onClose={() => setOpen(null)}>
        {(deposit) => (
          <div className="grid gap-5">
            <DepositFacts deposit={deposit} />
            <div className="grid gap-1.5">
              <span className="text-footnote text-muted-foreground">{sourceOf(deposit).body}</span>
              {deposit.body ? (
                <p dir="auto" className="rounded-lg border border-border bg-fill px-3 py-2.5 text-body wrap-anywhere whitespace-pre-wrap">
                  {deposit.body}
                </p>
              ) : (
                <p className="text-body text-muted-foreground">متنی نرسیده است.</p>
              )}
            </div>
            <DetailFooter user={deposit.payment?.customer ?? null} busy={false} onClose={() => setOpen(null)} />
          </div>
        )}
      </DetailModal>
    </>
  )
}

/**
 * Above the list while «تایید خودکار واریز» is off: no SMS approves a payment now, and the way to its switch on the
 * payment methods page. Nothing while its settings are unread or cannot be (the list says enough).
 */
export function DepositsOff() {
  const settings = useQuery(depositSettingsQuery).data
  if (!settings || settings.enabled) return null

  return (
    <ProgramOff to="/payment-methods" action="روشن کردن در روش‌های پرداخت">
      تایید خودکار واریز خاموش است و پیامک‌های بانک پرداختی را تایید نمی‌کنند.
    </ProgramOff>
  )
}

/** The sum in Toman, as the shop prices — or that the SMS named none. */
function Amount({ deposit }: { deposit: DepositRow }) {
  return deposit.amount === null ? 'بدون مبلغ' : <span className="tabular">{formatMoney(deposit.amount)}</span>
}

/** The bank the SMS came from, and its sender's number or name, left to right. */
function Bank({ deposit }: { deposit: DepositRow }) {
  if (deposit.bank === null && deposit.sender === null) return <Dash />

  return (
    <div className="grid">
      {deposit.bank !== null && <bdi>{deposit.bank}</bdi>}
      {deposit.sender !== null && (
        <bdi dir="ltr" className="text-footnote text-muted-foreground">
          {deposit.sender}
        </bdi>
      )}
    </div>
  )
}

/** The card payment a deposit approved, on the payments screen searched for it. */
function PaymentLink({ payment }: { payment: NonNullable<DepositRow['payment']> }) {
  return (
    <TextLink to={searchLink('/payments', payment.id)}>
      پرداخت <bdi dir="ltr">#{payment.id}</bdi>
    </TextLink>
  )
}

/** What came of it: the payment it approved — its order and its customer —, else why it approved none. */
function Outcome({ deposit }: { deposit: DepositRow }) {
  const { payment } = deposit
  if (payment === null) return deposit.note ? <span className="text-footnote text-muted-foreground">{isolateIds(deposit.note)}</span> : <Dash />

  return (
    <div className="grid gap-1">
      <span className="flex flex-wrap items-center gap-x-2 text-footnote">
        <PaymentLink payment={payment} />
        <TextLink to={searchLink('/orders', payment.order_id)}>
          سفارش <bdi dir="ltr">#{payment.order_id}</bdi>
        </TextLink>
      </span>
      <UserIdentity user={payment.customer} to={userPage(payment.customer.id)} />
    </div>
  )
}

/** Everything known of a deposit, in its dialog. */
function DepositFacts({ deposit }: { deposit: DepositRow }) {
  const { payment } = deposit

  return (
    <FactList>
      <Fact label="وضعیت">
        <StatusBadge status={DEPOSIT_STATUS[deposit.status]} />
      </Fact>
      {deposit.note && <Fact label="توضیح">{isolateIds(deposit.note)}</Fact>}
      <Fact label="مبلغ">
        <Amount deposit={deposit} />
        {deposit.amount_rial !== null && <span className="ms-2 text-footnote text-muted-foreground tabular">{formatNumber(deposit.amount_rial)} ریال</span>}
      </Fact>
      <Fact label="بانک">
        <Bank deposit={deposit} />
      </Fact>
      <Fact label="زمان">{formatDate(arrived(deposit))}</Fact>
      {payment && (
        <>
          <Fact label="پرداخت">
            <span className="flex flex-wrap items-center gap-x-3">
              <PaymentLink payment={payment} />
              <TextLink to={searchLink('/orders', payment.order_id)}>
                سفارش <bdi dir="ltr">#{payment.order_id}</bdi>
              </TextLink>
            </span>
          </Fact>
          <CustomerFacts user={payment.customer} />
        </>
      )}
    </FactList>
  )
}
