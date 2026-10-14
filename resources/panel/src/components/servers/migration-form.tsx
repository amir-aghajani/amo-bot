import { keepPreviousData, useQuery, type QueryKey } from '@tanstack/react-query'
import { ArrowLeftRight, LifeBuoy, TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { GrantOption } from '@/components/grants/grant-terms'
import { serversQuery } from '@/components/servers/queries'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { api } from '@/lib/api'
import type { ServerMigrationMode, ServerMigrationPreview, ServerMigrationRow, ServerRow } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { MIGRATION_MODE } from '@/lib/statuses'
import { useForm } from '@/lib/use-form'
import { useSettled } from '@/lib/use-settled'

/** The dialog's choices: the target as its select holds it (blank until one is chosen), and the plans ticked (none: every service). */
interface Values {
  target_server_id: string
  mode: ServerMigrationMode
  include_disabled: boolean
  notify: boolean
  plan_ids: number[]
}

/** What each way does, under its choice. */
const MODE_HINTS: Record<ServerMigrationMode, string> = {
  migrate: 'پنل این سرور جواب می‌دهد: هر سرویس از روی پنل خوانده می‌شود، با زمان و حجم باقی‌مانده‌اش روی سرور مقصد ساخته می‌شود و از این سرور حذف می‌شود.',
  rescue:
    'پنل این سرور از دسترس خارج شده است: هر سرویس با آخرین اطلاعاتی که فروشگاه از آن دارد روی سرور مقصد ساخته می‌شود و پنل این سرور پرسیده نمی‌شود. کلاینت‌های قبلی روی آن می‌مانند و وقتی دوباره جواب بدهد، خودکار حذف می‌شوند.',
}

interface MigrationFormProps {
  server: ServerRow
  /** What a move started changes elsewhere: its card, the services, the servers. */
  invalidates: readonly QueryKey[]
  onCancel: () => void
  onStarted: (job: ServerMigrationRow) => void
}

/**
 * Starting a move of the server's services: the target (a server that cannot take services now shows why), the way —
 * «نجات» chosen at first when the server's last contact failed —, the services switched off by support, the customers'
 * message, and some plans only. What it would move is read from the server as the choices change, once they settle: how
 * many, of which shops, and whether the target has room for them; a move of nothing does not start.
 */
export function MigrationForm({ server, invalidates, onCancel, onStarted }: MigrationFormProps) {
  const servers = useQuery(serversQuery)
  const { values, patch, error, formError, busy, dirty, submit, handleSubmit } = useForm<Values>(() => ({
    target_server_id: '',
    mode: server.last_error !== null ? 'rescue' : 'migrate',
    include_disabled: false,
    notify: true,
    plan_ids: [],
  }))
  const target = values.target_server_id === '' ? null : Number(values.target_server_id)
  // Read once the choices settle: plans ticked in a row ask once.
  const filters = useSettled({ target_server_id: target, include_disabled: values.include_disabled, plan_ids: values.plan_ids })
  const preview = useQuery({
    queryKey: queryKeys.migrationPreview(server.id, filters),
    queryFn: () => api.post(`/servers/${server.id}/migrations/preview`, filters),
    placeholderData: keepPreviousData,
  })
  const others = (servers.data?.servers ?? []).filter((other) => other.id !== server.id)

  const start = handleSubmit(async () => {
    if (target === null) return
    const result = await submit(
      () =>
        api.post(`/servers/${server.id}/migrations`, {
          target_server_id: target,
          mode: values.mode,
          include_disabled: values.include_disabled,
          notify: values.notify,
          plan_ids: values.plan_ids.length > 0 ? values.plan_ids : null,
        }),
      { invalidates },
    )
    if (result) onStarted(result.migration)
  })

  const togglePlan = (id: number, ticked: boolean) => patch({ plan_ids: ticked ? [...values.plan_ids, id] : values.plan_ids.filter((plan) => plan !== id) })

  return (
    <form onSubmit={start} noValidate className="grid gap-4">
      {servers.error && <ErrorState what="لیست سرورها" error={servers.error} onRetry={() => void servers.refetch()} retrying={servers.isFetching} />}

      <Field id="migration_target" label="سرور مقصد" error={error('target_server_id')}>
        {(control) => (
          <Select value={values.target_server_id} onValueChange={(target_server_id) => patch({ target_server_id })}>
            <SelectTrigger {...control} data-autofocus className="w-full">
              <SelectValue placeholder={servers.isPending ? 'در حال بارگذاری…' : 'یک سرور انتخاب کنید'} />
            </SelectTrigger>
            <SelectContent>
              {others.map((other) => (
                <SelectItem
                  key={other.id}
                  value={String(other.id)}
                  disabled={other.unsellable_reason !== null}
                  hint={other.unsellable_reason ?? `${formatNumber(other.counts.active_subscriptions)} سرویس فعال${other.capacity !== null ? ` از ظرفیت ${formatNumber(other.capacity)}` : ''}`}
                >
                  {other.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </Field>

      <Field id="migration_mode" label="روش" error={error('mode')} hint={MODE_HINTS[values.mode]}>
        {(control) => (
          <Select value={values.mode} onValueChange={(mode) => patch({ mode: mode as ServerMigrationMode })}>
            <SelectTrigger {...control} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="migrate" hint="پنل این سرور جواب می‌دهد">
                {MIGRATION_MODE.migrate}
              </SelectItem>
              <SelectItem value="rescue" hint="پنل این سرور از دسترس خارج شده است">
                {MIGRATION_MODE.rescue}
              </SelectItem>
            </SelectContent>
          </Select>
        )}
      </Field>

      <div className="grid gap-3">
        <GrantOption
          label="سرویس‌های غیرفعال‌شده هم منتقل شوند"
          hint="سرویس‌هایی که پشتیبانی غیرفعال کرده است، غیرفعال روی سرور مقصد ساخته می‌شوند. سرویس‌های تمام‌شده منتقل نمی‌شوند."
          checked={values.include_disabled}
          onCheckedChange={(include_disabled) => patch({ include_disabled })}
        />
        <GrantOption label="پیام به مشتری‌ها" hint="هر مشتری لینک جدید را در ربات فروشگاه خودش می‌گیرد." checked={values.notify} onCheckedChange={(notify) => patch({ notify })} />
      </div>

      {preview.data && preview.data.plans.length > 1 && (
        <fieldset className="grid gap-2">
          <legend className="mb-1 text-body font-medium">فقط سرویس‌های این پلن‌ها</legend>
          {preview.data.plans.map((plan) => (
            <GrantOption
              key={plan.id}
              label={plan.name}
              hint={`${plan.shop} · ${formatNumber(plan.services)} سرویس`}
              checked={values.plan_ids.includes(plan.id)}
              onCheckedChange={(ticked) => togglePlan(plan.id, ticked)}
            />
          ))}
          <p className="text-footnote text-muted-foreground">
            {values.plan_ids.length === 0
              ? 'هیچ‌کدام انتخاب نشده است: همه سرویس‌ها منتقل می‌شوند.'
              : preview.data.unplanned > 0
                ? `${formatNumber(preview.data.unplanned)} سرویس بدون پلن منتقل نمی‌شود.`
                : ''}
          </p>
        </fieldset>
      )}

      <Reach preview={preview.data} error={preview.error} chosen={target !== null} />

      <FormActions
        error={formError}
        onCancel={onCancel}
        submitLabel="شروع انتقال"
        busy={busy}
        disabled={target === null || !preview.data || preview.data.services === 0 || (preview.data.target?.problem ?? null) !== null}
        icon={values.mode === 'rescue' ? LifeBuoy : ArrowLeftRight}
        dirty={dirty}
      />
    </form>
  )
}

/** What the move would carry, as the server counts it: how many, of which shops — and the target's room, or why it cannot take them. */
function Reach({ preview, error, chosen }: { preview: ServerMigrationPreview | undefined; error: Error | null; chosen: boolean }) {
  if (error) return <ErrorState what="شمار سرویس‌ها" error={error} />
  if (!preview) return null
  if (preview.services === 0) return <p className="text-body text-muted-foreground">روی این سرور سرویسی با این انتخاب برای انتقال نیست.</p>

  const shops = preview.shops.map((row) => `${row.shop.name} ${formatNumber(row.services)}`).join('، ')
  const room = preview.target?.room ?? null

  return (
    <div className="grid gap-2">
      <p className="text-body text-muted-foreground" role="status">
        {formatNumber(preview.services)} سرویس منتقل می‌شود ({shops})؛ لینک هر کدام عوض می‌شود.
      </p>
      {preview.target?.problem && (
        <Callout tone="danger" icon={TriangleAlert}>
          {preview.target.problem}
        </Callout>
      )}
      {chosen && room !== null && room < preview.services && (
        <Callout tone="warning" icon={TriangleAlert}>
          ظرفیت سرور مقصد فقط برای {formatNumber(room)} سرویس دیگر جا دارد؛ بعد از آن انتقال ناتمام می‌ماند و بقیه روی همین سرور هستند.
        </Callout>
      )}
    </div>
  )
}
