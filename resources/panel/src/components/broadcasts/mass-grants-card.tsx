import { queryOptions } from '@tanstack/react-query'
import { Gift } from 'lucide-react'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { GrantCard, GrantItem } from '@/components/grants/grant-card'
import { reached } from '@/components/grants/grant-format'
import { GRANT_TERMS, GrantTermsFields, reachOf, type GrantTerms } from '@/components/grants/grant-terms'
import { useGrants } from '@/components/grants/use-grants'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { api } from '@/lib/api'
import type { MassGrantAudience, MassGrantRow, MassGrantsResponse } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { trimmed, useForm } from '@/lib/use-form'

const AUDIENCES: Record<MassGrantAudience, string> = {
  all: 'همه سرویس‌ها',
  agents: 'فقط سرویس‌های نماینده‌ها',
  server: 'سرویس‌های یک سرور',
  group: 'سرویس‌های مشتری‌های یک گروه',
}

const massGrantsQuery = queryOptions({ queryKey: queryKeys.massGrants, queryFn: () => api.get<MassGrantsResponse>('/mass-grants') })

/** Every server still under way waits for its panel: the runner asks again in half a minute. */
const waiting = (grant: MassGrantRow) => {
  const running = grant.parts.filter((part) => part.status === 'running')
  return running.length > 0 && running.every((part) => part.waiting_reason !== null)
}

/** Whom a gift is for: everyone's services, the agents', one server's or one customer group's customers'. */
function audienceLabel(grant: MassGrantRow): string {
  switch (grant.audience) {
    case 'server':
      return `سرویس‌های «${grant.server?.name ?? 'سرور حذف‌شده'}»`
    case 'group':
      return grant.group ? `سرویس‌های گروه «${grant.group.name}»` : 'سرویس‌های گروهی که حذف شد'
    case 'agents':
      return 'سرویس‌های نماینده‌ها'
    default:
      return 'همه سرویس‌ها'
  }
}

/**
 * «هدیه همگانی»: days and traffic for running services on every server at once, or only agents', one customer group's or
 * one server's. Each server's part is a grant of its own, so a panel out of reach holds up only its server.
 */
export function MassGrantsCard() {
  const grants = useGrants({
    query: massGrantsQuery,
    run: (id) => api.post(`/mass-grants/${id}/run`),
    cancel: (id) => api.post(`/mass-grants/${id}/cancel`),
    waiting,
    // The services it extended, the servers' own grants cards, the servers' counts, the dashboard.
    invalidates: [queryKeys.subscriptions, queryKeys.everyServerGrants, queryKeys.servers, queryKeys.dashboards],
    noun: 'هدیه همگانی',
  })
  const audience = grants.read.data?.audience

  return (
    <GrantCard
      title="هدیه‌ها"
      description="هر هدیه روی هر سرور جدا اجرا می‌شود؛ سروری که در دسترس نباشد فقط بخش خودش را نگه می‌دارد و بخش هر سرور در صفحه همان سرور هم دیده می‌شود."
      noun="هدیه همگانی"
      addLabel="هدیه جدید"
      listLabel="هدیه‌های قبلی"
      read={grants.read}
      running={grants.running}
      cancel={grants.cancel}
      item={(grant, stop) => (
        <GrantItem
          grant={grant}
          audience={audienceLabel(grant)}
          detail={`روی ${formatNumber(grant.parts.length)} سرور: ${grant.parts.map((part) => `${part.server.name} ${formatNumber(reached(part))}/${formatNumber(part.total)}`).join('، ')}`}
          waiting={grant.parts.filter((part) => part.status === 'running' && part.waiting_reason).map((part) => `${part.server.name}: ${part.waiting_reason}`)}
          waitingNote="کار روی این سرورها تا رفع مشکل پنلشان منتظر می‌ماند و خودکار دوباره تلاش می‌شود؛ بقیه سرورها جلو می‌روند."
          failure={grant.parts.find((part) => part.last_failure)?.last_failure ?? null}
          onStop={stop}
        />
      )}
      add={{
        title: 'هدیه همگانی',
        form: (close) =>
          audience && (
            <MassGrantForm
              audience={audience}
              onCancel={close}
              onStarted={(grant) => {
                grants.put(grant)
                close()
                toast.success('هدیه همگانی شروع شد')
              }}
            />
          ),
      }}
      stopDetails="سرویس‌هایی که تا این لحظه گرفته‌اند همان را نگه می‌دارند؛ همه سرورها متوقف می‌شوند."
    />
  )
}

interface MassGrantFormProps {
  audience: MassGrantsResponse['audience']
  onCancel: () => void
  onStarted: (grant: MassGrantRow) => void
}

/** A mass gift as its form holds it: a grant's terms, whom it is for, and the server or the group picked for one's services. */
type MassGrantDraft = GrantTerms & { audience: MassGrantAudience; server_id: string; group_id: string }

/** The gift as the API takes it: a server named only for one server's services, a group only for one group's. */
const bodyOf = (values: MassGrantDraft) => ({
  ...values,
  server_id: values.audience === 'server' ? values.server_id : '',
  group_id: values.audience === 'group' ? values.group_id : '',
})

function MassGrantForm({ audience, onCancel, onStarted }: MassGrantFormProps) {
  // A server picked and then left for everyone's services is no part of the gift: what is unsaved is what it sends.
  const { values, set, patch, error, formError, busy, dirty, submit, handleSubmit } = useForm<MassGrantDraft>(
    { ...GRANT_TERMS, audience: 'all', server_id: '', group_id: '' },
    { reads: (draft) => trimmed(bodyOf(draft)) },
  )
  const server = audience.servers.find((s) => String(s.id) === values.server_id)
  const group = audience.groups.find((g) => String(g.id) === values.group_id)
  // Not known while one server's — or one group's — services are meant and none is picked: the save then asks for it.
  const reach = values.audience === 'server' ? (server ?? null) : values.audience === 'group' ? (group ?? null) : audience[values.audience]

  const start = handleSubmit(async () => {
    const result = await submit(() => api.post('/mass-grants', bodyOf(values)))
    if (result) onStarted(result.grant)
  })

  return (
    <form onSubmit={start} noValidate className="grid gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="mass_audience" label="به چه سرویس‌هایی" error={error('audience')}>
          {(control) => (
            <Select value={values.audience} onValueChange={(value) => set('audience', value as MassGrantAudience)}>
              <SelectTrigger {...control} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {Object.entries(AUDIENCES).map(([value, label]) => (
                  <SelectItem key={value} value={value}>
                    {label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
        {values.audience === 'server' && (
          <TargetSelect
            id="mass_server"
            label="سرور"
            placeholder="انتخاب سرور"
            options={audience.servers}
            value={values.server_id}
            onChange={(value) => set('server_id', value)}
            error={error('server_id')}
          />
        )}
        {values.audience === 'group' && (
          <TargetSelect
            id="mass_group"
            label="گروه مشتریان"
            placeholder="انتخاب گروه"
            options={audience.groups}
            value={values.group_id}
            onChange={(value) => set('group_id', value)}
            error={error('group_id')}
            empty="هنوز گروهی نیست؛ گروه‌ها در صفحه کاربران ساخته می‌شوند."
          />
        )}
      </div>
      <GrantTermsFields
        values={values}
        change={patch}
        error={error}
        reach={reach}
        choose={values.audience === 'group' ? 'اول گروهی انتخاب کنید که به سرویس‌های مشتری‌هایش اضافه شود.' : undefined}
        reasonPlaceholder="مثلا: هدیه نوروز"
      />
      <FormActions error={formError} onCancel={onCancel} submitLabel="شروع هدیه" busy={busy} disabled={reach !== null && reachOf(reach, values) === 0} icon={Gift} dirty={dirty} />
    </form>
  )
}

interface TargetSelectProps {
  id: string
  label: string
  placeholder: string
  /** The servers or groups that have services, each with how many run. */
  options: { id: number; name: string; running: number }[]
  value: string
  onChange: (value: string) => void
  error?: string
  /** What to say, the select held, while there is nothing to pick. */
  empty?: string
}

/** The one server, or the one customer group, a gift for "one of them" goes to. */
function TargetSelect({ id, label, placeholder, options, value, onChange, error, empty }: TargetSelectProps) {
  const none = options.length === 0 && empty !== undefined
  return (
    <Field id={id} label={label} error={error} hint={none ? empty : undefined}>
      {(control) => (
        <Select value={value} onValueChange={onChange} disabled={none}>
          <SelectTrigger {...control} className="w-full">
            <SelectValue placeholder={placeholder} />
          </SelectTrigger>
          <SelectContent>
            {options.map((option) => (
              <SelectItem key={option.id} value={String(option.id)}>
                {option.name} ({formatNumber(option.running)} سرویس)
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
    </Field>
  )
}
