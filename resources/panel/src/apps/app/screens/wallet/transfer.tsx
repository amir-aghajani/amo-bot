import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Send, Wallet } from 'lucide-react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { keys, queryClient } from '@/apps/app/lib/query'
import { withRecentSignIn } from '@/apps/app/lib/recent'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { useAttempt } from '@/apps/app/parts/checkout/attempt'
import { result } from '@/apps/app/telegram/haptics'
import { confirmBox } from '@/apps/app/telegram/webapp'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Chip, Field, TextInput } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Empty, ErrorView, LoadingBlocks } from '@/apps/app/ui/states'
import { told } from '@/apps/app/ui/toaster'
import { IconTile } from '@/apps/app/ui/visuals'
import type { StoreAccountResponse, StoreRecipient, StoreTransferRecipientResponse, StoreWalletResponse } from '@/lib/api-types'
import { digitsOnly, formatBalance, formatMoney, initials, MONEY_UNIT, tomanOf } from '@/lib/format'

/*
 * «انتقال موجودی»: money from the wallet to another customer of the shop, as the bot sends it. The recipient is found by
 * their numeric Telegram id and shown by their name to be sure of them; then the amount, within what the wallet may
 * send now (the shop's bounds, the balance, what today's cap leaves), confirmed with both. Sending it asks a recent
 * sign-in (lib/recent proves one when the session's is old), and a key of its own: pressed again after no answer came, it
 * finds the transfer made rather than making another.
 */

/** Whom the customer reads the recipient as: their Telegram name, else their handle. */
function recipientName(recipient: StoreRecipient): string {
  return recipient.name ?? (recipient.username === null ? 'کاربر بدون نام' : `@${recipient.username}`)
}

/** The recipient found, as a card: their initials, name and handle, and the way to pick another. */
function RecipientCard({ recipient, onChange }: { recipient: StoreRecipient; onChange: () => void }) {
  const name = recipientName(recipient)

  return (
    <div className="card animate-rise flex items-center gap-3 p-4">
      <span className="bg-accent text-on-accent grid size-12 shrink-0 place-items-center rounded-full text-heading font-bold">{initials(name) || '؟'}</span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="text-hint text-caption">گیرنده</span>
        <span className="truncate text-body font-semibold">{name}</span>
        {recipient.username !== null && recipient.name !== null && <span className="ltr text-hint truncate text-right text-footnote">@{recipient.username}</span>}
      </span>
      <Button variant="secondary" size="sm" onClick={onChange}>
        تغییر
      </Button>
    </div>
  )
}

export default function TransferScreen() {
  const navigate = useNavigate()
  const wallet = useQuery({ queryKey: keys.wallet, queryFn: () => store.get<StoreWalletResponse>('/wallet') })
  const [telegramId, setTelegramId] = useState('')
  /** The recipient the shop found, with the id they were found by. */
  const [found, setFound] = useState<{ id: string; recipient: StoreRecipient } | null>(null)
  const [typed, setTyped] = useState('')
  const attempt = useAttempt()

  const lookup = useMutation({
    mutationFn: (id: string) => store.get<StoreTransferRecipientResponse>('/wallet/transfer', new URLSearchParams({ telegram_id: id })),
    meta: { quiet: true },
    onSuccess: ({ recipient }, id) => {
      result('success')
      setFound({ id, recipient })
    },
    onError: (error) => {
      result('error')
      if (fieldProblem(error, 'telegram_id') === undefined && fieldProblem(error, 'amount') === undefined) toast.error(problemOf(error))
    },
  })

  const send = useMutation({
    mutationFn: (body: { telegram_id: string; amount: string }) => {
      const key = attempt.key(body)
      return withRecentSignIn('تایید انتقال موجودی', () => store.post('/wallet/transfer', body, { idempotencyKey: key }))
    },
    meta: { quiet: true },
    onSuccess: ({ transfer, wallet: now }, body) => {
      attempt.done(body)
      queryClient.setQueryData(keys.wallet, now)
      queryClient.setQueryData<StoreAccountResponse>(keys.me, (account) => (account === undefined ? account : { ...account, customer: { ...account.customer, balance: now.balance } }))
      void queryClient.invalidateQueries({ queryKey: keys.transactions })
      void queryClient.invalidateQueries({ queryKey: keys.me })
      told(`${formatMoney(transfer.amount)} به ${recipientName(transfer.recipient)} منتقل شد.`)
      navigate('/wallet', { replace: true })
    },
    onError: (error, body) => {
      attempt.failed(error, body)
      result('error')
      if (fieldProblem(error, 'amount') === undefined && fieldProblem(error, 'telegram_id') === undefined) toast.error(problemOf(error))
    },
  })

  // The form stays while there is a wallet read: one read again that failed takes neither it nor what was typed away.
  if (wallet.data === undefined) {
    return (
      <Screen back title="انتقال موجودی">
        {wallet.isError ? <ErrorView error={wallet.error} retry={() => void wallet.refetch()} /> : <LoadingBlocks count={2} />}
      </Screen>
    )
  }

  const limits = wallet.data.transfer
  if (limits === null) {
    return (
      <Screen back title="انتقال موجودی">
        <Empty icon={Send} title="انتقال موجودی فعال نیست" text="انتقال موجودی در این فروشگاه فعال نیست." />
      </Screen>
    )
  }

  const min = Number(limits.min)
  const most = Number(limits.most)
  if (most < min) {
    const capped = limits.left_today !== null && Number(limits.left_today) < min

    return (
      <Screen back title="انتقال موجودی">
        <Empty
          icon={Wallet}
          title="فعلا انتقالی ممکن نیست"
          text={
            capped
              ? 'سقف انتقال روزانه شما برای امروز پر شده است؛ فردا دوباره امتحان کنید.'
              : `برای انتقال، دست‌کم ${formatMoney(limits.min)} موجودی در کیف پول لازم است. موجودی شما ${formatBalance(wallet.data.balance)} است.`
          }
          action={!capped && <Button onClick={() => navigate('/wallet/top-up')}>شارژ کیف پول</Button>}
        />
      </Screen>
    )
  }

  const amount = typed.trim() === '' ? null : tomanOf(typed)
  const amountProblem =
    typed.trim() !== '' && amount === null
      ? 'مبلغ را به تومان و بدون اعشار بنویسید.'
      : amount !== null && amount < min
        ? `کمترین مبلغ انتقال ${formatMoney(limits.min)} است.`
        : amount !== null && amount > most
          ? `بیشترین مبلغی که الان می‌توانید منتقل کنید ${formatMoney(limits.most)} است.`
          : send.isError
            ? fieldProblem(send.error, 'amount')
            : undefined
  const ready = amount !== null && amountProblem === undefined
  const idProblem = lookup.isError ? (fieldProblem(lookup.error, 'telegram_id') ?? fieldProblem(lookup.error, 'amount')) : undefined
  const recipientProblem = send.isError ? fieldProblem(send.error, 'telegram_id') : undefined

  /** A new amount: the last refusal was of another. */
  const type = (value: string) => {
    send.reset()
    setTyped(value)
  }
  const confirmAndSend = async () => {
    if (found === null || amount === null) return
    const confirmed = await confirmBox(`${formatMoney(amount)} به ${recipientName(found.recipient)} منتقل شود؟ این کار برگشت ندارد.`)
    if (confirmed) send.mutate({ telegram_id: found.id, amount: typed.trim() })
  }

  return (
    <Screen back title="انتقال موجودی" subtitle={`موجودی: ${formatBalance(wallet.data.balance)}`} bottom="action">
      {found === null ? (
        <>
          <div className="flex flex-col items-center gap-3 px-6 pt-2 text-center">
            <IconTile icon={Send} size="2xl" look="soft" round />
            <p className="text-hint max-w-xs text-footnote">موجودی کیف پول خود را بدون کارمزد به مشتری دیگری از همین فروشگاه بفرستید.</p>
          </div>
          <Field label="شناسه عددی تلگرام گیرنده" error={idProblem} hint="گیرنده باید دست‌کم یک بار ربات فروشگاه را باز کرده باشد.">
            {(id) => (
              <TextInput
                id={id}
                ltr
                inputMode="numeric"
                autoComplete="off"
                placeholder="123456789"
                value={telegramId}
                invalid={idProblem !== undefined}
                onChange={(event) => {
                  lookup.reset()
                  setTelegramId(event.target.value)
                }}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' && digitsOnly(telegramId) !== '') lookup.mutate(telegramId.trim())
                }}
              />
            )}
          </Field>
          <MainAction text="ادامه" enabled={digitsOnly(telegramId) !== ''} busy={lookup.isPending} onClick={() => lookup.mutate(telegramId.trim())} />
        </>
      ) : (
        <>
          <RecipientCard
            recipient={found.recipient}
            onChange={() => {
              send.reset()
              setFound(null)
            }}
          />
          {recipientProblem !== undefined && (
            <p className="-mt-3 px-4 text-caption text-danger" role="alert">
              {recipientProblem}
            </p>
          )}
          <Field
            label="مبلغ انتقال"
            error={amountProblem}
            hint={
              amount !== null
                ? `${formatMoney(amount)} منتقل می‌شود`
                : `از ${formatMoney(limits.min)} تا ${formatMoney(limits.most)}${limits.left_today === null ? '' : ` · باقی‌مانده سقف امروز: ${formatMoney(limits.left_today)}`}`
            }
          >
            {(id) => (
              <div className="relative">
                <TextInput
                  id={id}
                  inputMode="numeric"
                  autoComplete="off"
                  placeholder="مثلا ۵۰۰۰۰"
                  value={typed}
                  invalid={amountProblem !== undefined}
                  onChange={(event) => type(event.target.value)}
                  className="pe-16"
                />
                <span className="text-hint pointer-events-none absolute inset-y-0 end-4 flex items-center text-footnote">{MONEY_UNIT}</span>
              </div>
            )}
          </Field>
          {/* Each on a line of its own when the two do not fit side by side (a 320px phone). */}
          <div className="flex flex-wrap gap-2">
            <Chip active={amount === min} onClick={() => type(String(min))}>
              {`کمترین: ${formatMoney(limits.min)}`}
            </Chip>
            <Chip active={amount === most} onClick={() => type(String(most))}>
              {`بیشترین: ${formatMoney(limits.most)}`}
            </Chip>
          </div>
          <MainAction text={ready ? `انتقال ${formatMoney(amount)}` : 'انتقال'} enabled={ready} busy={send.isPending} onClick={() => void confirmAndSend()} />
        </>
      )}
    </Screen>
  )
}
