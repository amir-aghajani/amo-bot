import { useEffect, useId, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { ArrowLeftRight, Link2, RefreshCw, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { ConfirmActions } from '@/components/confirm-modal'
import { CopyBox } from '@/components/copy-box'
import { ErrorState } from '@/components/error-state'
import { FormError } from '@/components/form-footer'
import { useReadFailure } from '@/components/page'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { GatewayWebhookState, PaymentMethodRow, PaymentMethodWebhookResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { queryKeys } from '@/lib/query-keys'
import type { Status } from '@/lib/statuses'

/*
 * A gateway that keeps one webhook address per merchant account, which its API reads and sets (Rialit's): the shop
 * registers its own address when a method of it is saved and the gateway has none, and never puts it over another
 * site's by itself — the owner does, here. Every change is a security notice to the account's owner (Telegram and
 * email), and the gateway takes a few of them an hour.
 */

/** Where the gateway sends its webhook, in a word. */
const STATUS: Record<GatewayWebhookState['state'], Status> = {
  ours: { label: 'آدرس فروشگاه ثبت است', tone: 'success' },
  none: { label: 'ثبت نشده', tone: 'warning' },
  elsewhere: { label: 'به آدرس دیگری می‌رود', tone: 'warning' },
  unknown: { label: 'معلوم نیست', tone: 'neutral' },
}

/**
 * Puts the gateway's word — a save's, a registration's — in place of the read. Registered just now (`ours` with a
 * message), its message is said once, in the toast that says so: the block keeps the state alone.
 */
export function keepWebhook(client: QueryClient, methodId: number, webhook: GatewayWebhookState): void {
  client.setQueryData<PaymentMethodWebhookResponse>(queryKeys.paymentMethodWebhook(methodId), { webhook: webhook.state === 'ours' ? { ...webhook, message: null } : webhook })
}

/**
 * A method's save said, with what came of its gateway's webhook (`webhook`, a Rialit method's; null for any other):
 * registered just now, its message under the save's; not registered — the address refused, too many changes this hour,
 * the gateway not read — a warning with why; sent to another site's address the view did not show (`news`), a warning
 * that the view is where to replace it (the gateway's word on it is said there). Nothing done (registered already, or
 * the other address the view showed), the save alone.
 */
export function toastSaved(saved: string, webhook: GatewayWebhookState | null, gateway: string, news: boolean): void {
  if (webhook === null || webhook.state === 'ours' || (webhook.state === 'elsewhere' && !news)) {
    say(toast.success, saved, webhook?.state === 'ours' ? webhook.message : null)
  } else if (webhook.state === 'elsewhere') {
    say(toast.warning, `${saved}، ولی وب‌هوک ${gateway} به آدرس دیگری می‌رود`, 'در همین پنجره می‌توانید آن را با آدرس فروشگاه جایگزین کنید.')
  } else {
    say(toast.warning, `${saved}، ولی ${webhook.state === 'none' ? `آدرس فروشگاه در ${gateway} ثبت نشد` : `وضعیت وب‌هوک ${gateway} معلوم نشد`}`, webhook.message)
  }
}

/** A toast with the gateway's word under it when it gave one. */
function say(kind: typeof toast.success, title: string, description: string | null): void {
  if (description) kind(title, { description })
  else kind(title)
}

interface GatewayWebhookProps {
  method: PaymentMethodRow
  /** Counted up each time the block is what to read next (a save found another address): it takes the focus — and as the dialog opens, with a value. */
  attention: number
  /** The form around it is saving (which registers the address too): its buttons wait. */
  held: boolean
}

/**
 * «وب‌هوک ریالیت» in a method's view: where the gateway sends its webhook now, read as the view opens (`GET
 * …/webhook`), and the way on from each state — none: register the shop's address; another site's: replace it, asked
 * first in place (that site stops getting the webhooks, and the gateway tells the account's owner); not read: why, and
 * ask again. Registering (`POST …/webhook`) answers the state as it is after, put in place of the read; a refusal of
 * the gateway's comes as that state with why. Until it is the shop's, the address to paste in the gateway's dashboard
 * by hand is there to copy. Whatever was pressed and went, the focus goes to what took its place.
 */
export function GatewayWebhook({ method, attention, held }: GatewayWebhookProps) {
  const titleId = useId()
  const queryClient = useQueryClient()
  const gateway = method.driver_label
  const read = useQuery({
    queryKey: queryKeys.paymentMethodWebhook(method.id),
    queryFn: () => api.get<PaymentMethodWebhookResponse>(`/payment-methods/${method.id}/webhook`),
  })
  const failure = useReadFailure(read)
  const [asking, setAsking] = useState(false)
  const register = useMutation({
    mutationFn: (replace: boolean) => api.post(`/payment-methods/${method.id}/webhook`, { replace }),
    meta: { quiet: true },
    onSuccess: ({ webhook }) => {
      if (webhook.state === 'ours') toast.success(webhook.message ?? `آدرس فروشگاه در ${gateway} ثبت شد`)
      keepWebhook(queryClient, method.id, webhook)
      setAsking(false)
    },
  })
  const busy = register.isPending || read.isFetching
  const waits = busy || held

  const block = useRef<HTMLElement>(null)
  /** The state's own button (register, replace, ask again): where the focus goes back to while it is there. */
  const action = useRef<HTMLButtonElement>(null)
  // Something pressed here settles: if what had the focus went with it (the confirm, a button the new state has not),
  // the state's button takes it, else the block — never the document.
  const settling = useRef(false)
  useEffect(() => {
    if (!settling.current || busy) return
    settling.current = false
    const active = document.activeElement
    if (active instanceof HTMLElement && active !== document.body && active.tagName !== 'DIALOG' && active.isConnected) return
    ;(action.current ?? block.current)?.focus()
  })
  // Said to be read next (a save found another address): the block takes the focus.
  useEffect(() => {
    if (attention > 0) block.current?.focus()
  }, [attention])

  const settle = (run: () => void) => {
    settling.current = true
    run()
  }

  const webhook = read.data?.webhook
  const failed = register.error ? messageOf(register.error) : null

  return (
    <section
      ref={block}
      aria-labelledby={titleId}
      tabIndex={-1}
      // As the dialog opens on it (a method just added whose gateway sends elsewhere), the dialog's first focus.
      data-autofocus={attention > 0 ? '' : undefined}
      className="grid gap-3 rounded-lg border border-border px-3 py-2.5 outline-none focus-visible:focus-ring"
    >
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
        <h3 id={titleId} className="text-body font-medium">
          وب‌هوک {gateway}
        </h3>
        {webhook ? <StatusBadge status={STATUS[webhook.state]} /> : !failure.error && <Skeleton className="h-5 w-28 rounded-full" />}
      </div>

      {webhook === undefined ? (
        failure.error ? (
          <ErrorState what={`وضعیت وب‌هوک ${gateway}`} error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />
        ) : (
          <Skeleton className="h-4 w-3/4" />
        )
      ) : (
        <>
          <WebhookState webhook={webhook} gateway={gateway} />

          {webhook.state !== 'ours' &&
            (asking ? (
              <div className="grid gap-3 rounded-lg border border-danger-line/70 bg-danger-soft/35 p-3">
                <p className="flex items-center gap-2 text-body font-medium text-danger">
                  <ArrowLeftRight className="size-4 shrink-0" aria-hidden />
                  جایگزین کردن با آدرس فروشگاه
                </p>
                <p className="text-footnote text-muted-foreground">
                  آدرس فعلی دیگر وب‌هوک‌های {gateway} را نمی‌گیرد: اگر سایت یا ربات دیگری با همین حساب کار می‌کند، خبر پرداخت‌هایش به آن نمی‌رسد. {gateway} این تغییر را در تلگرام و ایمیل به صاحب حساب
                  خبر می‌دهد.
                </p>
                <FormError message={failed} failure={register.error} />
                <ConfirmActions
                  size="sm"
                  confirmLabel="جایگزین کردن"
                  icon={ArrowLeftRight}
                  destructive
                  busy={register.isPending}
                  waiting={held}
                  focusCancel
                  onCancel={() =>
                    settle(() => {
                      register.reset()
                      setAsking(false)
                    })
                  }
                  onConfirm={() => settle(() => register.mutate(true))}
                />
              </div>
            ) : (
              <div className="flex flex-wrap gap-2">
                {webhook.state === 'none' && (
                  <Button ref={action} size="sm" icon={Link2} busy={register.isPending} aria-disabled={waits} onClick={() => settle(() => register.mutate(false))}>
                    ثبت آدرس فروشگاه
                  </Button>
                )}
                {webhook.state === 'elsewhere' && (
                  <Button
                    ref={action}
                    size="sm"
                    variant="secondary"
                    icon={ArrowLeftRight}
                    aria-disabled={waits}
                    onClick={() => {
                      register.reset()
                      setAsking(true)
                    }}
                  >
                    جایگزین کردن با آدرس فروشگاه
                  </Button>
                )}
                {webhook.state === 'unknown' && (
                  <Button ref={action} size="sm" variant="secondary" icon={RefreshCw} busy={read.isFetching} aria-disabled={waits} onClick={() => settle(() => void read.refetch())}>
                    دوباره امتحان
                  </Button>
                )}
              </div>
            ))}
          {!asking && <FormError message={failed ?? (failure.error ? messageOf(failure.error) : null)} failure={register.error ?? failure.error} />}

          {/* The way by hand, while the address is not the shop's: the shop's address to paste in the gateway's dashboard. */}
          {webhook.state !== 'ours' && (
            <CopyBox
              className="border-t border-border pt-3"
              label={`یا این آدرس را خودتان در داشبورد ${gateway} به عنوان آدرس وب‌هوک بگذارید:`}
              name="آدرس وب‌هوک فروشگاه"
              value={webhook.ours}
              copied="آدرس وب‌هوک کپی شد"
            />
          )}
        </>
      )}
    </section>
  )
}

/** What each state means, the other site's address set apart left to right, and the gateway's word on it when it gave one. */
function WebhookState({ webhook, gateway }: { webhook: GatewayWebhookState; gateway: string }) {
  const note = webhook.message ? (
    <Callout tone="warning" icon={TriangleAlert} role="status">
      {webhook.message}
    </Callout>
  ) : null

  switch (webhook.state) {
    case 'ours':
      return <p className="text-footnote text-muted-foreground">{webhook.message ?? `${gateway} خبر هر پرداخت را به آدرس همین فروشگاه می‌فرستد.`}</p>
    case 'none':
      return (
        <>
          <p className="text-footnote text-muted-foreground">{gateway} آدرسی برای وب‌هوک ندارد و خبر پرداخت‌ها را به فروشگاه نمی‌فرستد.</p>
          {note}
        </>
      )
    case 'elsewhere':
      return (
        <>
          <div className="grid gap-1">
            <p className="text-footnote text-muted-foreground">{gateway} خبر پرداخت‌ها را به این آدرس می‌فرستد، نه به این فروشگاه:</p>
            <code dir="ltr" className="text-start text-footnote break-all text-foreground select-all">
              {webhook.url}
            </code>
          </div>
          {note}
        </>
      )
    case 'unknown':
      return note ?? <p className="text-footnote text-muted-foreground">وضعیت وب‌هوک از {gateway} خوانده نشد.</p>
  }
}
