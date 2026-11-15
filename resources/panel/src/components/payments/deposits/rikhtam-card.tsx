import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { Info, KeyRound, Plug, RefreshCw, TriangleAlert, Unlink, type LucideIcon } from 'lucide-react'
import { toast } from 'sonner'
import { Step } from '@/components/bot/connect-steps'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { CopyBox } from '@/components/copy-box'
import { Field } from '@/components/field'
import { FormError } from '@/components/form-footer'
import { keepDepositSettings } from '@/components/payments/deposits/queries'
import { SecretInput } from '@/components/secret-input'
import { StatusBadge } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { api, ApiError } from '@/lib/api'
import type { DepositSettingsResponse } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatDate, formatMoney } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useForm } from '@/lib/use-form'

/** Rikhtam's site: its panel, where the owner makes the shop's gateway and copies its key. */
const RIKHTAM = 'https://rikhtam.com'

/** The least a card payment may be for Rikhtam to confirm its deposit, in Toman. */
const RIKHTAM_MINIMUM = 10_000

/** What connecting or letting Rikhtam go changes besides the settings: whether each open card payment offers «پیوست واریز ریختم». */
const RIKHTAM_CHANGES = [queryKeys.payments, queryKeys.everyPayment]

/** The shop's domain as its address on Rikhtam names it — the one Rikhtam's gateway is made with; null for none. */
function hostOf(address: string): string | null {
  try {
    return new URL(address).host || null
  } catch {
    return null
  }
}

/**
 * Once `value` changes, `target` takes the focus if what had it went with the change (or it was the document's) — what
 * took the place of what was pressed —; a focus put elsewhere stays.
 */
function useFocusOnChange(value: boolean, target: () => HTMLElement | null | undefined): void {
  const last = useRef(value)
  useEffect(() => {
    if (last.current === value) return
    last.current = value
    const active = document.activeElement
    if (!(active instanceof HTMLElement) || active === document.body || !active.isConnected) target()?.focus()
  })
}

/**
 * Rikhtam connected to the shop, the other way its bank's deposits reach it: Rikhtam's app reads the bank's SMS and
 * tells the shop of each deposit, for a fee of its own on each one confirmed. Not connected, its steps and the gateway's
 * «کلید امنیتی» to paste; connected, the gateway and what to do with it. Each change answers the settings whole, put in
 * place of the read. Connected or let go here, what was pressed goes with it: the focus goes to what took its place
 * (the gateway, or the key's field), never to the document.
 */
export function RikhtamCard({ settings }: { settings: DepositSettingsResponse }) {
  const { connected } = settings.rikhtam
  const content = useRef<HTMLDivElement>(null)
  // The part drawn now marks what takes it (`data-takes-focus`).
  useFocusOnChange(connected, () => content.current?.querySelector<HTMLElement>('[data-takes-focus]'))

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">اتصال ریختم</CardTitle>
          <CardDescription>
            ریختم پیامک‌های واریز حساب بانکی را در برنامه خودش می‌خواند و هر واریز را به فروشگاه خبر می‌دهد؛ برای هر واریزی که تایید شود کارمزد خودش را می‌گیرد. فوراپ رایگان است.
          </CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent ref={content} className="grid gap-5">
        {connected ? <RikhtamConnected settings={settings} /> : <RikhtamConnect settings={settings} />}
      </CardContent>
    </Card>
  )
}

/**
 * Rikhtam not connected yet: the steps in Rikhtam's panel and app, and the gateway's key (RikhtamKeyForm), which
 * registers the shop's address with Rikhtam (nothing to paste back) and switches the automatic approval on while it is
 * off.
 */
function RikhtamConnect({ settings }: { settings: DepositSettingsResponse }) {
  const domain = hostOf(settings.rikhtam.callback_url)

  return (
    <>
      <ol className="grid gap-2.5 text-body">
        <Step n={1}>
          در{' '}
          <TextLink href={RIKHTAM} inline>
            پنل ریختم
          </TextLink>
          ، بخش «درگاه‌ها»، یک درگاه با دامنه این فروشگاه
          {domain !== null && (
            <>
              {' '}
              (<bdi dir="ltr">{domain}</bdi>)
            </>
          )}{' '}
          بسازید و «کلید امنیتی» آن را کپی کنید.
        </Step>
        <Step n={2}>کلید را این‌جا بگذارید و «اتصال» را بزنید؛ فروشگاه آدرس خودش را در ریختم ثبت می‌کند و لازم نیست چیزی را آن‌جا بگذارید.</Step>
        <Step n={3}>در برنامه ریختم، حساب بانکی کارت‌هایی را که مشتری‌ها به آن‌ها واریز می‌کنند به همین درگاه وصل کنید.</Step>
        <Step n={4}>با «تست سلامت درگاه» در پنل یا برنامه ریختم، اتصال را بسنجید.</Step>
      </ol>

      {/* Rikhtam tells only an https address: a shop on another one is told so before it pastes a key Rikhtam would refuse. */}
      {settings.rikhtam.callback_url.startsWith('https://') ? (
        <RikhtamKeyForm
          settings={settings}
          submitLabel="اتصال"
          icon={Plug}
          invalidates={RIKHTAM_CHANGES}
          onSaved={() => toast.success(settings.enabled ? 'ریختم وصل شد' : 'ریختم وصل شد و تایید خودکار واریز روشن شد')}
        >
          {!settings.enabled && (
            <Callout tone="info" icon={Info}>
              تایید خودکار واریز الان خاموش است؛ با اتصال ریختم روشن می‌شود.
            </Callout>
          )}
        </RikhtamKeyForm>
      ) : (
        <Callout tone="warning" icon={TriangleAlert}>
          آدرس سایت فروشگاه با https شروع نمی‌شود و ریختم فقط به آدرس https خبر می‌دهد؛ تا آدرس سایت https نشود، ریختم وصل نمی‌شود.
        </Callout>
      )}
    </>
  )
}

interface RikhtamKeyFormProps {
  settings: DepositSettingsResponse
  submitLabel: string
  icon: LucideIcon
  /** Saved: the settings answered are put in place of the read first. */
  onSaved: () => void
  /** What the change touches besides the settings (connecting: the payments' «پیوست واریز ریختم»). */
  invalidates?: readonly QueryKey[]
  /** A key replaced while connected: the field takes the focus, and the form can be put away. */
  onCancel?: () => void
  /** Above the field (the approval switched on with it). */
  children?: ReactNode
}

/**
 * The Rikhtam gateway's «کلید امنیتی», sent as `PUT /deposits/settings/rikhtam` — to connect, or, connected, to replace a
 * key Rikhtam changed or revoked: either way it is kept and the shop's address registered again. A key Rikhtam refuses
 * is said under it; refused as made for another domain, the shop's address for the gateway's «آدرس کال‌بک» is offered
 * to copy (connected, it is on the card already).
 */
function RikhtamKeyForm({ settings, submitLabel, icon, onSaved, invalidates, onCancel, children }: RikhtamKeyFormProps) {
  const id = useId()
  const queryClient = useQueryClient()
  const { values, set, error, formError, failure, busy, submit, handleSubmit } = useForm({ secret: '' })
  const replacing = onCancel !== undefined

  const save = handleSubmit(async () => {
    if (values.secret.trim() === '') return
    const result = await submit(() => api.put('/deposits/settings/rikhtam', { secret: values.secret.trim() }), { invalidates })
    if (result === undefined) return
    keepDepositSettings(queryClient, result)
    onSaved()
  })

  return (
    <form onSubmit={save} noValidate className="grid gap-3">
      {children}
      <Field id={`${id}-secret`} label="کلید امنیتی درگاه ریختم" error={error('secret')} hint="«کلید امنیتی» درگاهی که در پنل ریختم برای این فروشگاه ساخته‌اید.">
        {(control) => (
          <div className="flex flex-wrap items-start gap-2">
            <div className="min-w-0 flex-1 basis-64">
              <SecretInput {...control} data-takes-focus="" autoFocus={replacing} value={values.secret} onChange={(e) => set('secret', e.target.value)} />
            </div>
            <div className="flex flex-wrap gap-2">
              {onCancel && (
                <Button variant="secondary" onClick={onCancel} disabled={busy}>
                  انصراف
                </Button>
              )}
              <Button type="submit" icon={icon} busy={busy} aria-disabled={values.secret.trim() === ''}>
                {submitLabel}
              </Button>
            </div>
          </div>
        )}
      </Field>
      {!replacing && error('secret')?.includes(settings.rikhtam.callback_url) && (
        <CopyBox
          label="آدرس فروشگاه برای ریختم"
          value={settings.rikhtam.callback_url}
          copied="آدرس فروشگاه کپی شد"
          hint={`در پنل ریختم، آن را در «آدرس کال‌بک» همان درگاه بگذارید و دوباره «${submitLabel}» را بزنید.`}
        />
      )}
      <FormError message={formError} failure={failure} />
    </form>
  )
}

/**
 * Rikhtam connected: its gateway's domain and since when, the shop's address registered there (to copy, for reference),
 * how a deposit that matched nothing is attached, registering the address again (`POST …/rikhtam/hook`), and forgetting
 * the key (`DELETE …/rikhtam`, asked first). While the automatic approval is off, it says Rikhtam's deposits approve
 * nothing by themselves — one attached by hand still does.
 */
function RikhtamConnected({ settings }: { settings: DepositSettingsResponse }) {
  const { rikhtam } = settings
  const queryClient = useQueryClient()
  const [asking, setAsking] = useState(false)
  const hook = useMutation({
    mutationFn: () => api.post('/deposits/settings/rikhtam/hook'),
    meta: { quiet: true },
    onSuccess: (result) => {
      keepDepositSettings(queryClient, result)
      toast.success('آدرس فروشگاه دوباره در ریختم ثبت شد')
    },
  })
  const forget = useMutation({
    mutationFn: () => api.delete('/deposits/settings/rikhtam'),
    meta: { quiet: true, invalidates: RIKHTAM_CHANGES },
    onSuccess: (result) => {
      keepDepositSettings(queryClient, result)
      setAsking(false)
      toast.success('اتصال ریختم قطع شد')
    },
  })
  const busy = hook.isPending || forget.isPending
  const domain = rikhtam.domain ?? hostOf(rikhtam.callback_url)
  // A key Rikhtam changed or revoked, replaced here (RikhtamKeyForm): put away, the focus goes back to «تعویض کلید».
  const [replacing, setReplacing] = useState(false)
  const replace = useRef<HTMLButtonElement>(null)
  useFocusOnChange(replacing, () => replace.current)
  // Registering again refused on the key (or what it was made for): a new key is the way on.
  const keyRefused = hook.error instanceof ApiError && hook.error.field('secret') !== undefined

  return (
    <>
      {/* Focused once connected here (RikhtamCard): the gateway and «وصل است» read in one. */}
      <div data-takes-focus="" tabIndex={-1} className="-m-1 flex flex-wrap items-start justify-between gap-3 rounded-lg p-1 outline-none focus-visible:focus-ring">
        <div className="grid min-w-0 gap-0.5">
          <span className="font-medium">{domain === null ? 'درگاه ریختم' : <bdi dir="ltr">{domain}</bdi>}</span>
          <span className="text-footnote text-muted-foreground">
            {rikhtam.project_id !== null && (
              <>
                درگاه شماره <bdi dir="ltr">{rikhtam.project_id}</bdi> در ریختم ·{' '}
              </>
            )}
            وصل شده در {formatDate(rikhtam.connected_at)}
          </span>
        </div>
        <StatusBadge status={{ label: 'وصل است', tone: 'success' }} />
      </div>

      {!settings.enabled && (
        <Callout tone="warning" icon={TriangleAlert}>
          تایید خودکار واریز خاموش است: تا روشنش نکنید، واریزهای ریختم خودکار هیچ پرداختی را تایید نمی‌کنند، ولی پیوست دستی یک واریز به پرداخت همچنان کار می‌کند.
        </Callout>
      )}

      <CopyBox
        label="آدرس فروشگاه در ریختم"
        value={rikhtam.callback_url}
        copied="آدرس فروشگاه کپی شد"
        hint="فروشگاه این آدرس را خودش در ریختم ثبت کرده است؛ برای مراجعه است و لازم نیست جایی بگذارید."
      />

      <ul className="grid list-disc gap-1.5 ps-5 text-footnote text-muted-foreground">
        <li>پرداختی که مشتری مبلغش را گرد کند خودکار تایید نمی‌شود: واریزش را در برنامه ریختم با شماره پرداخت، یا در بررسی همان پرداخت با «پیوست واریز ریختم» به آن وصل کنید.</li>
        <li>ریختم پرداخت‌های کمتر از {formatMoney(RIKHTAM_MINIMUM)} را تایید نمی‌کند.</li>
        <li>سلامت اتصال را با «تست سلامت درگاه» در پنل یا برنامه ریختم بسنجید.</li>
      </ul>

      {/* Every one held while one runs — held, not disabled, so the one pressed keeps the focus. */}
      <div className="flex flex-wrap gap-2">
        <Button variant="secondary" icon={RefreshCw} busy={hook.isPending} aria-disabled={busy || replacing} onClick={() => hook.mutate()}>
          ثبت دوباره آدرس
        </Button>
        <Button
          ref={replace}
          variant="secondary"
          icon={KeyRound}
          aria-disabled={busy || replacing}
          aria-expanded={replacing}
          onClick={() => {
            hook.reset()
            setReplacing(true)
          }}
        >
          تعویض کلید
        </Button>
        <Button
          variant="danger"
          icon={Unlink}
          aria-disabled={busy}
          onClick={() => {
            forget.reset()
            setAsking(true)
          }}
        >
          قطع اتصال
        </Button>
      </div>
      <FormError message={hook.error ? messageOf(hook.error, 'secret') : null} failure={hook.error} />
      {keyRefused && <p className="text-footnote text-muted-foreground">اگر کلید درگاه در پنل ریختم عوض یا حذف شده است، کلید تازه را با «تعویض کلید» بگذارید.</p>}

      {replacing && (
        <div className="grid gap-3 border-t border-border pt-5">
          <p className="text-footnote text-muted-foreground">
            کلید تازه درگاه را از پنل ریختم، بخش «درگاه‌ها»، کپی کنید و این‌جا بگذارید؛ فروشگاه آن را جای کلید فعلی نگه می‌دارد و آدرس خودش را دوباره در ریختم ثبت می‌کند.
          </p>
          <RikhtamKeyForm
            settings={settings}
            submitLabel="ذخیره کلید"
            icon={KeyRound}
            onCancel={() => setReplacing(false)}
            onSaved={() => {
              setReplacing(false)
              toast.success('کلید ریختم عوض شد و آدرس فروشگاه دوباره در ریختم ثبت شد')
            }}
          />
        </div>
      )}

      <ConfirmModal
        open={asking}
        onClose={() => setAsking(false)}
        title="قطع اتصال ریختم"
        confirmLabel="قطع اتصال"
        destructive
        pending={forget.isPending}
        error={forget.error ? messageOf(forget.error) : null}
        onConfirm={() => forget.mutate()}
      >
        کلید امنیتی از فروشگاه پاک می‌شود و واریزهایی که ریختم از این به بعد خبر می‌دهد هیچ پرداختی را تایید نمی‌کنند. درگاه در پنل ریختم سر جایش می‌ماند؛ برای وصل کردن دوباره، کلیدش را دوباره
        بگذارید.
      </ConfirmModal>
    </>
  )
}
