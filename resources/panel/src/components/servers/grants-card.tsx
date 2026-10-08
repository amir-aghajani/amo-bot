import { queryOptions } from '@tanstack/react-query'
import { Gift } from 'lucide-react'
import { toast } from 'sonner'
import { FormActions } from '@/components/form-footer'
import { GrantCard, GrantItem } from '@/components/grants/grant-card'
import { GRANT_TERMS, GrantTermsFields, reachOf } from '@/components/grants/grant-terms'
import { useGrants } from '@/components/grants/use-grants'
import { api } from '@/lib/api'
import type { GrantReach, ServerGrantRow, ServerGrantsResponse, ServerRow } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { queryKeys } from '@/lib/query-keys'
import { useForm } from '@/lib/use-form'

const grantsQuery = (serverId: number) => queryOptions({ queryKey: queryKeys.serverGrants(serverId), queryFn: () => api.get<ServerGrantsResponse>(`/servers/${serverId}/grants`) })

/** Its panel is out of reach: the runner asks again in half a minute. */
const waiting = (grant: ServerGrantRow) => grant.waiting_reason !== null

/**
 * «افزودن زمان و حجم» on a server's page: days and traffic for the running services on it — and, ticked, those
 * still waiting for their first connection — an outage made good, a gift, with the reason the customers read in
 * the bot; and the latest grants with how far each got, a mass gift's part on this server among them. The one under
 * way is worked on from here while the page is open; the scheduler carries on without it.
 */
export function GrantsCard({ server }: { server: ServerRow }) {
  const grants = useGrants({
    query: grantsQuery(server.id),
    run: (id) => api.post(`/servers/${server.id}/grants/${id}/run`),
    cancel: (id) => api.post(`/servers/${server.id}/grants/${id}/cancel`),
    waiting,
    // The services it extended — and any its panel had ended, now marked so —, the server's counts, the dashboard.
    invalidates: [queryKeys.subscriptions, queryKeys.server(server.id), queryKeys.servers, queryKeys.dashboards],
    noun: 'افزودن زمان و حجم',
  })
  const audience = grants.read.data?.audience

  return (
    <GrantCard
      title="افزودن زمان و حجم"
      description="روز و حجم اضافه برای سرویس‌های فعال این سرور، مثلا برای جبران قطعی."
      noun="افزودن زمان و حجم"
      addLabel="افزودن به سرویس‌ها"
      listLabel="موارد قبلی"
      read={grants.read}
      running={grants.running}
      cancel={grants.cancel}
      item={(grant, stop) => (
        <GrantItem
          grant={grant}
          waiting={grant.waiting_reason ? [grant.waiting_reason] : []}
          waitingNote="کار روی این سرور تا رفع این مشکل پنل منتظر می‌ماند؛ خودکار دوباره تلاش می‌شود."
          failure={grant.last_failure}
          notes={[...(grant.mass_grant_id !== null ? [`بخشی از هدیه همگانی ${idLabel(grant.mass_grant_id)}`] : []), ...(grant.agents_only ? ['فقط سرویس‌های نماینده‌ها'] : [])]}
          onStop={stop}
        />
      )}
      add={{
        title: 'افزودن زمان و حجم',
        description: `به سرویس‌های «${server.name}»`,
        form: (close) =>
          audience && (
            <GrantForm
              server={server}
              reach={audience}
              onCancel={close}
              onStarted={(grant) => {
                grants.put(grant)
                close()
                toast.success('افزودن زمان و حجم شروع شد')
              }}
            />
          ),
      }}
      stopDetails="سرویس‌هایی که تا این لحظه گرفته‌اند همان را نگه می‌دارند."
    />
  )
}

interface GrantFormProps {
  server: ServerRow
  reach: GrantReach
  onCancel: () => void
  onStarted: (grant: ServerGrantRow) => void
}

function GrantForm({ server, reach, onCancel, onStarted }: GrantFormProps) {
  const { values, patch, error, formError, busy, dirty, submit, handleSubmit } = useForm(GRANT_TERMS)

  const start = handleSubmit(async () => {
    const result = await submit(() => api.post(`/servers/${server.id}/grants`, values))
    if (result) onStarted(result.grant)
  })

  return (
    <form onSubmit={start} noValidate className="grid gap-4">
      <GrantTermsFields values={values} change={patch} error={error} reach={reach} where="روی این سرور" reasonPlaceholder="مثلا: جبران قطعی سرور" />
      <FormActions error={formError} onCancel={onCancel} submitLabel="افزودن به سرویس‌ها" busy={busy} disabled={reachOf(reach, values) === 0} icon={Gift} dirty={dirty} />
    </form>
  )
}
