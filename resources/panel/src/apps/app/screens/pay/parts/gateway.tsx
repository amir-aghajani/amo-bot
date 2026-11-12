import { useEffect, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Headset, Hourglass, RefreshCw, Timer, TimerOff } from 'lucide-react'
import { keys, queryClient } from '@/apps/app/lib/query'
import { useShop } from '@/apps/app/lib/session'
import { problemOf, store } from '@/apps/app/lib/store'
import { openContact } from '@/apps/app/screens/pay/parts/order-words'
import { OrderFacts, Outcome, PayAgain } from '@/apps/app/screens/pay/parts/outcome'
import { BankCard, TransferAmount } from '@/apps/app/screens/pay/parts/transfer'
import { useAppActive } from '@/apps/app/telegram/active'
import { result } from '@/apps/app/telegram/haptics'
import { inPreview, openLink, openTelegramLink } from '@/apps/app/telegram/webapp'
import { Button, MainAction } from '@/apps/app/ui/button'
import { CopyValue } from '@/apps/app/ui/fields'
import { Section } from '@/apps/app/ui/section'
import { Notice } from '@/apps/app/ui/states'
import { Countdown, Price, useReached } from '@/apps/app/ui/visuals'
import { ApiError, stateChanged } from '@/lib/api'
import type { StoreOrder, StorePayment } from '@/lib/api-types'
import { formatMoney } from '@/lib/format'

/*
 * Paying an order on an online gateway's page: the sum it asks (the gateway's fee in it, when the customer pays one),
 * how long the page takes money, and «پرداخت در درگاه», which opens it in Telegram's browser (or, for a Telegram mini
 * app gateway, in Telegram) while this screen stays behind and watches: the order is read every few seconds (the
 * screen above), the gateway is asked as the customer comes back, and «بررسی پرداخت» asks it now
 * (`POST /payments/{id}/check`, held for its Retry-After when asked too often). A gateway that gave a card may be paid
 * card to card instead, the exact sum, no receipt. A page whose time is up is opened no more: the gateway is asked what
 * came of it, or the order paid again. A page closed unpaid is paid again as a new attempt. A payment held
 * for support (its `held`, as the order says it) is paid no more and asked about no more: it says so in the shop's words,
 * with its tracking code to quote and support's door — as does a check that finds it held meanwhile.
 */

/** What the customer is told while the gateway has nothing yet (the bot's words). */
const NOT_YET = 'هنوز پرداختی ثبت نشده است. اگر پرداخت کرده‌اید، چند لحظه بعد دوباره «بررسی پرداخت» را بزنید.'

interface Said {
  tone: 'info' | 'warning' | 'danger'
  text: string
  /** The payment is with support (a 422 on `status`): their door beside it. */
  support: boolean
}

export function OnlinePayment({ order, payment }: { order: StoreOrder; payment: StorePayment }) {
  const { data: shop } = useShop()
  const support = shop?.support?.url ?? null
  const link = payment.link
  const [opened, setOpened] = useState(false)
  const [closed, setClosed] = useState(false)
  const [said, setSaid] = useState<Said | null>(null)
  const [hold, setHold] = useState<{ seconds: number } | null>(null)
  // The page's time up: it takes no money now, and is opened no more.
  const lapsed = useReached(link?.expires_at ?? null)
  const expired = link !== null && link.expires_at !== null && lapsed

  useEffect(() => {
    if (hold === null) return
    const timer = setTimeout(() => setHold(null), hold.seconds * 1000)
    return () => clearTimeout(timer)
  }, [hold])

  const check = useMutation({
    mutationFn: () => store.post(`/payments/${payment.id}/check`),
    meta: { quiet: true },
    onSuccess: ({ check: answer }) => {
      queryClient.setQueryData(keys.order(order.id), { order: answer.order })
      if (answer.state === 'paid') {
        result('success')
        setSaid(null)
      } else if (answer.state === 'closed') {
        result('warning')
        setClosed(true)
        setSaid(null)
      } else {
        result('warning')
        setSaid({ tone: 'info', text: NOT_YET, support: false })
      }
    },
    onError: (error) => {
      result('error')
      if (error instanceof ApiError && error.status === 429) setHold({ seconds: error.facts.retryAfter ?? 60 })
      // A 422 on `status`: held for support meanwhile — the order read again says so.
      const held = stateChanged(error)
      if (held) void queryClient.invalidateQueries({ queryKey: keys.order(order.id) })
      setSaid({ tone: held ? 'warning' : 'danger', text: problemOf(error), support: held })
    },
  })
  const { mutate: ask } = check

  // Back from the gateway's page — the page shown again, or the app back in front in Telegram: ask it what came of it
  // (not in a panel's preview, which asks nothing by itself).
  const active = useAppActive()
  const wasActive = useRef(active)
  useEffect(() => {
    const back = active && !wasActive.current
    wasActive.current = active
    if (opened && back && !inPreview()) ask()
  }, [opened, active, ask])

  const heard =
    said === null ? null : (
      <div role="alert" className="animate-rise flex flex-col gap-2">
        <Notice tone={said.tone}>{said.text}</Notice>
        {said.support && support !== null && (
          <Button variant="secondary" block onClick={() => openContact(support)}>
            پیام به پشتیبانی
          </Button>
        )}
      </div>
    )

  if (payment.held) return <HeldForSupport order={order} payment={payment} support={support} />

  if (payment.status !== 'pending' || closed) {
    return (
      <>
        <Outcome tone="warning" icon={TimerOff} title="پرداخت انجام نشد" text="پرداختی در درگاه ثبت نشد. اگر هنوز می‌خواهید، دوباره پرداخت کنید." />
        {payment.note !== null && <Notice tone="warning">{payment.note}</Notice>}
        {heard}
        <PayAgain order={order} />
      </>
    )
  }

  if (link === null || expired) {
    // Its page closed, or its time up: what came of it is the gateway's to say — or, its time up, paid again.
    return (
      <>
        {expired ? (
          <Outcome tone="warning" icon={TimerOff} title="مهلت پرداخت تمام شد" text="اگر پیش از پایان مهلت پرداخت کرده‌اید، «بررسی پرداخت» را بزنید؛ وگرنه دوباره پرداخت کنید." />
        ) : (
          <Outcome tone="accent" icon={Hourglass} title="در انتظار نتیجه پرداخت" text="اگر در درگاه پرداخت کرده‌اید، «بررسی پرداخت» را بزنید." />
        )}
        {payment.note !== null && <Notice tone="warning">{payment.note}</Notice>}
        {heard}
        {expired && <PayAgain order={order} inline className="self-center" />}
        <MainAction text="بررسی پرداخت" enabled={hold === null} busy={check.isPending} onClick={() => ask()} />
      </>
    )
  }

  const gateway = () => {
    setOpened(true)
    if (link.url.startsWith('https://t.me/')) openTelegramLink(link.url)
    else openLink(link.url)
  }

  return (
    <>
      <section className="card animate-rise flex flex-col items-center p-5 text-center">
        <span className="text-hint text-footnote">مبلغ پرداخت · {payment.method.label}</span>
        <Price amount={link.amount} className="mt-1 text-display" />
        {link.fee !== null && Number(link.fee) > 0 && <span className="text-hint text-caption">شامل {formatMoney(link.fee)} کارمزد درگاه</span>}
        {link.expires_at !== null && (
          <span className="mt-4 inline-flex h-8 items-center gap-2 rounded-full bg-fill px-3 text-footnote leading-none whitespace-nowrap">
            <Timer aria-hidden className="text-hint size-4 shrink-0" />
            <span>
              مهلت پرداخت: <Countdown to={link.expires_at} done="تمام شد" />
            </span>
          </span>
        )}
      </section>

      <div className="card flex items-center gap-3 p-4">
        <span aria-hidden className="relative flex size-3 shrink-0">
          <span className="bg-accent absolute inline-flex size-full animate-ping rounded-full opacity-60" />
          <span className="bg-accent relative inline-flex size-3 rounded-full" />
        </span>
        <span className="min-w-0 flex-1 text-footnote">پس از پرداخت، سفارش خودکار تحویل می‌شود و رسیدی لازم نیست؛ این صفحه منتظر تایید درگاه است.</span>
        <Button variant="secondary" size="sm" icon={<RefreshCw />} busy={check.isPending} disabled={hold !== null} onClick={() => ask()}>
          بررسی پرداخت
        </Button>
      </div>
      {heard}

      {link.card !== null && (
        <Section title="یا کارت به کارت" footer="دقیقا همین مبلغ را به این کارت واریز کنید؛ درگاه واریز را با مبلغش می‌شناسد و رسیدی لازم نیست.">
          <div className="flex flex-col gap-4 p-4">
            <BankCard number={link.card.number} holder={link.card.holder} bank={link.card.bank} />
            <TransferAmount amount={link.amount} rial={link.amount_rial} />
          </div>
        </Section>
      )}

      <MainAction text="پرداخت در درگاه" shine onClick={gateway} />
    </>
  )
}

/** An online payment held for support: not to be paid again — the shop's words on it, its tracking code, support's door. */
function HeldForSupport({ order, payment, support }: { order: StoreOrder; payment: StorePayment; support: string | null }) {
  return (
    <>
      <Outcome tone="warning" icon={Headset} title="پرداخت نزد پشتیبانی است" text={payment.note ?? 'این پرداخت برای بررسی به پشتیبانی رسیده است؛ دوباره پرداخت نکنید.'} />
      {payment.reference !== null && (
        <div className="card p-4">
          <CopyValue label="کد رهگیری" value={payment.reference} done="کد رهگیری کپی شد" />
        </div>
      )}
      <OrderFacts order={order} />
      <MainAction text={support === null ? null : 'پیام به پشتیبانی'} onClick={() => support !== null && openContact(support)} />
    </>
  )
}
