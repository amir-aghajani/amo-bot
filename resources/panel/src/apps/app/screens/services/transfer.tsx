import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ArrowLeftRight, UserRound, WifiOff } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { keys, queryClient } from '@/apps/app/lib/query'
import { withRecentSignIn } from '@/apps/app/lib/recent'
import { useShop } from '@/apps/app/lib/session'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { serviceTitle, useService } from '@/apps/app/parts/service'
import { serviceId } from '@/apps/app/screens/services/parts/service'
import { result } from '@/apps/app/telegram/haptics'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Field, TextInput } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Section } from '@/apps/app/ui/section'
import { Empty, ErrorView, LoadingBlocks, Notice } from '@/apps/app/ui/states'
import { told } from '@/apps/app/ui/toaster'
import { IconTile } from '@/apps/app/ui/visuals'
import { ApiError } from '@/lib/api'
import type { StoreRecipient, StoreSubscription, StoreTransferRecipientResponse } from '@/lib/api-types'
import { digitsOnly, initials } from '@/lib/format'

/*
 * «انتقال سرویس»: the service handed to another customer of the shop, free, as the bot does it — their numeric Telegram
 * id typed (Persian digits too), the customer they are shown to confirm (`GET …/transfer`, nothing changes), then the
 * service handed over (`POST …/transfer`) once the customer proved it is them again (lib/recent: a leaked token gives no
 * service away). The service is theirs from then on: the customer goes back to their services. What goes with it — its
 * link, so the giver's devices stay connected until the recipient changes it — is said before they confirm.
 */

export default function TransferScreen() {
  const navigate = useNavigate()
  const id = serviceId(useParams().id)
  const query = useService(id)
  const { data: shop } = useShop()
  const service = query.data?.subscription
  const gone = Number.isNaN(id) || (query.error instanceof ApiError && query.error.status === 404)

  return (
    <Screen back title="انتقال سرویس" subtitle={service === undefined ? undefined : serviceTitle(service)} bottom="action">
      {gone ? (
        <Empty icon={WifiOff} title="این سرویس در حساب شما نیست" action={<Button onClick={() => navigate('/services', { replace: true })}>سرویس‌های من</Button>} />
      ) : shop !== undefined && !shop.shop.service_transfers ? (
        <Empty icon={ArrowLeftRight} title="انتقال سرویس در این فروشگاه فعال نیست" />
      ) : service !== undefined ? (
        <Transfer service={service} />
      ) : query.isError ? (
        <ErrorView error={query.error} retry={() => void query.refetch()} />
      ) : (
        <LoadingBlocks count={2} />
      )}
    </Screen>
  )
}

function Transfer({ service }: { service: StoreSubscription }) {
  const navigate = useNavigate()
  const [typed, setTyped] = useState('')
  const telegramId = digitsOnly(typed)
  const lookup = useMutation({
    mutationFn: (id: string) => store.get<StoreTransferRecipientResponse>(`/subscriptions/${service.id}/transfer`, new URLSearchParams({ telegram_id: id })),
    meta: { quiet: true },
    onError: () => result('error'),
  })
  const recipient = lookup.isSuccess && lookup.variables === telegramId ? lookup.data.recipient : null
  const hand = useMutation({
    mutationFn: () => withRecentSignIn('تایید انتقال سرویس', () => store.post(`/subscriptions/${service.id}/transfer`, { telegram_id: telegramId })),
    meta: { quiet: true },
    onSuccess: ({ recipient: to }) => {
      told(`سرویس ${serviceTitle(service)} به ${nameOf(to)} منتقل شد.`)
      queryClient.removeQueries({ queryKey: keys.subscription(service.id) })
      void queryClient.invalidateQueries({ queryKey: keys.subscriptions })
      navigate('/services', { replace: true })
    },
    onError: () => result('error'),
  })
  const failure = hand.error ?? lookup.error
  const idProblem = failure === null ? undefined : fieldProblem(failure, 'telegram_id')

  return (
    <>
      <Notice tone="warning">
        سرویس با همان لینک، زمان و حجم به حساب گیرنده می‌رود و دیگر در حساب شما نیست. دستگاه‌هایی که با این لینک وصل‌اند تا وقتی گیرنده لینک را عوض نکند وصل می‌مانند؛ تمدید خودکار هم خاموش می‌شود.
      </Notice>

      <form
        onSubmit={(event) => {
          event.preventDefault()
          if (telegramId !== '') lookup.mutate(telegramId)
        }}
      >
        <Field label="شناسه عددی تلگرام گیرنده" hint="گیرنده باید یک بار ربات فروشگاه را استارت کرده باشد." error={idProblem}>
          {(fieldId) => (
            <TextInput
              id={fieldId}
              ltr
              inputMode="numeric"
              autoComplete="off"
              placeholder="123456789"
              value={typed}
              invalid={idProblem !== undefined}
              onChange={(event) => {
                setTyped(event.target.value)
                lookup.reset()
                hand.reset()
              }}
            />
          )}
        </Field>
      </form>

      {failure !== null && idProblem === undefined && <Notice tone="danger">{problemOf(failure)}</Notice>}

      {recipient !== null && (
        <Section title="گیرنده">
          <div className="animate-rise flex items-center gap-3 p-4">
            {recipient.name !== null && initials(recipient.name) !== '' ? (
              <span className="bg-accent text-on-accent inline-flex size-12 shrink-0 items-center justify-center rounded-full text-heading leading-none font-bold">{initials(recipient.name)}</span>
            ) : (
              <IconTile icon={UserRound} size="xl" round />
            )}
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="truncate text-heading font-semibold">{recipient.name ?? 'بدون نام'}</span>
              <span className="text-hint text-footnote">{recipient.username !== null ? <span className="ltr">@{recipient.username}</span> : <span className="ltr">{telegramId}</span>}</span>
            </span>
          </div>
        </Section>
      )}

      {/* The recipient's name stays in their card: a Telegram name is longer than the main button takes. */}
      <MainAction
        text={recipient === null ? 'ادامه' : 'تایید انتقال'}
        enabled={telegramId !== ''}
        busy={lookup.isPending || hand.isPending}
        onClick={() => (recipient === null ? lookup.mutate(telegramId) : hand.mutate())}
      />
    </>
  )
}

/** The recipient as the customer knows them: their name, else their handle, else «گیرنده». */
function nameOf(recipient: StoreRecipient): string {
  return recipient.name ?? (recipient.username === null ? 'گیرنده' : `@${recipient.username}`)
}
