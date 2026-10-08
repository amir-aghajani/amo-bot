import { useCallback, useId, useRef, useState } from 'react'
import type { UseQueryResult } from '@tanstack/react-query'
import { Plus, Server as ServerIcon, X } from 'lucide-react'
import { ErrorState } from '@/components/error-state'
import { IconButton } from '@/components/icon-button'
import { PageTabs } from '@/components/page-tabs'
import { TextLink } from '@/components/text-link'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import type { PlanInboundRef, PlanOptions, PlanServerEntryInput } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/** What the form sends: one row per server, either the whole server or a set of its inbounds — every field said. */
export type ServerEntryDraft = Required<PlanServerEntryInput>

type Mode = 'all' | 'pick'

const MODES: { value: Mode; label: string }[] = [
  { value: 'all', label: 'کل سرور' },
  { value: 'pick', label: 'انتخاب اینباندها' },
]

interface ServerEntriesProps {
  entries: ServerEntryDraft[]
  onChange: (entries: ServerEntryDraft[]) => void
  /** The form's read of the servers and their inbounds (lib/queries' planOptionsQuery). */
  options: UseQueryResult<PlanOptions>
  /** The save's refusals about the entries — every one, each about its own server. */
  errors?: string[]
  /**
   * Where servers are added (the owner's servers page), linked while there is none; absent in a panel that adds none —
   * and has no server's page either (an agent's): what is done on a server's page is then support's.
   */
  addServers?: string
}

/** An inbound sold whole-server: enabled on the panel and marked for sale on the server's page. */
const sellable = (inbound: PlanInboundRef) => inbound.enabled && inbound.is_selectable

/** An inbound as the admin knows it: its remark, else its protocol. */
const inboundName = (inbound: PlanInboundRef) => inbound.remark || inbound.protocol || `#${inbound.id}`

/** Its protocol and port, when it has them — a PasarGuard group has neither. */
function inboundAddress(inbound: PlanInboundRef): string {
  return [inbound.protocol, inbound.port].filter((part) => part !== null).join(':')
}

/**
 * Builds the list of servers a plan is sold on: pick a server, then either the whole server (every
 * inbound it marks sellable, evaluated at purchase time) or specific inbounds, and add it. The
 * customer later picks one of these servers when buying. Why a server cannot sell now is the server's
 * own answer (`unsellable_reason`); an entry on it stays, unseen by the customer until it can. Without a server to
 * pick — none read, or none there — it says so, and where one is added. It is one field of the plan's form: refused, it
 * is marked invalid as a whole (counted with the form's other fields, the focus taken there), its refusals under it.
 */
export function ServerEntries({ entries, onChange, options, errors = [], addServers }: ServerEntriesProps) {
  const servers = options.data?.servers ?? []
  const [serverId, setServerId] = useState('')
  const [mode, setMode] = useState<Mode>('all')
  const [picked, setPicked] = useState<number[]>([])
  // A panel with a server's page (the owner's) sends its admin there; an agent's inbounds are support's to set.
  const serverPages = addServers !== undefined
  const ids = useId()
  const invalid = errors.length > 0

  const remaining = servers.filter((s) => !entries.some((e) => e.server_id === s.id))
  const chosen = servers.find((s) => String(s.id) === serverId)
  const sellableCount = chosen ? chosen.inbounds.filter(sellable).length : 0
  const canAdd = !!chosen && (mode === 'all' || picked.length > 0)

  // The add that took the last server leaves no adder: its word «همه سرورها…» takes the focus the button had.
  const tookLast = useRef(false)
  const allAdded = useCallback((node: HTMLElement | null) => {
    if (node && tookLast.current) node.focus()
    tookLast.current = false
  }, [])

  const add = () => {
    if (!chosen || !canAdd) return
    tookLast.current = remaining.length === 1
    onChange([...entries, { server_id: chosen.id, all_inbounds: mode === 'all', inbound_ids: mode === 'all' ? [] : picked }])
    setServerId('')
    setMode('all')
    setPicked([])
  }

  const remove = (id: number) => onChange(entries.filter((e) => e.server_id !== id))

  return (
    <div
      role="group"
      aria-labelledby={`${ids}-title`}
      aria-describedby={invalid ? `${ids}-errors` : undefined}
      data-invalid={invalid ? '' : undefined}
      tabIndex={invalid ? -1 : undefined}
      className="grid gap-3 rounded-xl border border-border p-4 outline-none focus-visible:focus-ring"
    >
      <div className="grid gap-1">
        <span id={`${ids}-title`} className="text-body font-medium">
          سرورها
        </span>
        <p className="text-footnote leading-relaxed text-muted-foreground">مشتری هنگام خرید یکی از این سرورها را انتخاب می‌کند و یک اشتراک روی همه اینباندهای آن ردیف می‌گیرد.</p>
      </div>

      {entries.length > 0 && (
        <ul className="grid gap-2">
          {entries.map((entry) => {
            const server = servers.find((s) => s.id === entry.server_id)
            const inbounds = server ? server.inbounds.filter((i) => (entry.all_inbounds ? sellable(i) : entry.inbound_ids.includes(i.id))) : []
            const name = server?.name ?? `سرور ${idLabel(entry.server_id)}`
            return (
              <li key={entry.server_id} className="flex items-start gap-3 rounded-lg border border-border bg-fill px-3 py-2.5">
                <ServerIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
                <div className="grid min-w-0 flex-1 gap-1.5">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-body font-medium">{name}</span>
                    <span className="text-footnote text-muted-foreground">
                      {entry.all_inbounds ? `کل سرور · ${formatNumber(inbounds.length)} اینباند قابل فروش` : `${formatNumber(inbounds.length)} اینباند انتخاب‌شده`}
                    </span>
                  </div>
                  {server?.unsellable_reason && <p className="text-footnote text-warning">{server.unsellable_reason} تا درست نشود، مشتری این سرور را نمی‌بیند.</p>}
                  {inbounds.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                      {inbounds.map((inbound) => (
                        <Badge key={inbound.id} variant="outline" dir="ltr">
                          {inboundName(inbound)}
                          {inbound.port !== null && `:${inbound.port}`}
                        </Badge>
                      ))}
                    </div>
                  ) : (
                    <p className="text-footnote text-warning">
                      فعلا اینباند قابل فروشی روی این سرور نیست؛ {serverPages ? 'در صفحه سرور اینباندها را برای فروش علامت بزنید.' : 'از پشتیبانی بخواهید اینباندهایش را برای فروش علامت بزند.'}
                    </p>
                  )}
                </div>
                <IconButton aria-label={`حذف ${name}`} className="-me-1 size-7" onClick={() => remove(entry.server_id)}>
                  <X className="size-4" aria-hidden />
                </IconButton>
              </li>
            )
          })}
        </ul>
      )}

      {options.data === undefined ? (
        options.error ? (
          <ErrorState what="سرورها" error={options.error} onRetry={() => void options.refetch()} retrying={options.isFetching} />
        ) : (
          <Skeleton className="h-24 w-full" />
        )
      ) : servers.length === 0 ? (
        <p className={cn('rounded-lg border border-dashed border-border-strong p-3 text-footnote leading-relaxed text-muted-foreground', errors.length > 0 && 'border-danger-line')}>
          {addServers ? (
            <>
              هنوز سروری ندارید؛{' '}
              <TextLink to={addServers} inline>
                اول یک سرور اضافه کنید
              </TextLink>
              ، بعد اینجا انتخابش کنید.
            </>
          ) : (
            'فروشگاه هنوز سروری ندارد که پلن روی آن فروخته شود؛ از پشتیبانی بخواهید سروری اضافه کند.'
          )}
        </p>
      ) : remaining.length > 0 ? (
        <div className={cn('grid gap-3 rounded-lg border border-dashed border-border-strong p-3', entries.length === 0 && errors.length > 0 && 'border-danger-line')}>
          <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
            <Select
              value={serverId}
              onValueChange={(value) => {
                setServerId(value)
                setPicked([])
              }}
            >
              <SelectTrigger className="w-full" aria-label="سرور">
                <SelectValue placeholder="انتخاب سرور" />
              </SelectTrigger>
              <SelectContent>
                {remaining.map((s) => (
                  <SelectItem
                    key={s.id}
                    value={String(s.id)}
                    hint={s.unsellable_reason ? `${s.unsellable_reason} تا درست نشود، مشتری این پلن را روی آن نمی‌بیند.` : `${formatNumber(s.inbounds.filter(sellable).length)} اینباند قابل فروش`}
                  >
                    {s.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <PageTabs as="choice" value={mode} onChange={setMode} tabs={MODES} aria-label="نحوه افزودن" size="sm" className="w-fit" />
          </div>

          {chosen && mode === 'all' && (
            <p className="text-footnote leading-relaxed text-muted-foreground">
              {`هر اینباندی که ${serverPages ? 'در صفحه سرور «قابل فروش» باشد' : 'پشتیبانی روی این سرور برای فروش گذاشته باشد'}، حالا و در آینده، جزو این پلن است (الان ${formatNumber(sellableCount)} مورد).`}
            </p>
          )}

          {chosen && mode === 'pick' && (
            <ul className="grid gap-1 sm:grid-cols-2">
              {chosen.inbounds.map((inbound) => {
                const checked = picked.includes(inbound.id)
                return (
                  <li key={inbound.id}>
                    <label
                      className={cn(
                        'flex cursor-pointer items-center gap-2.5 rounded-lg border border-border px-2.5 py-1.5 text-body transition-colors',
                        checked && 'border-selected/60 bg-info-soft/40',
                        !inbound.enabled && 'cursor-not-allowed opacity-50',
                      )}
                    >
                      <Checkbox
                        checked={checked}
                        disabled={!inbound.enabled}
                        onCheckedChange={(value) => setPicked((current) => (value === true ? [...current, inbound.id] : current.filter((id) => id !== inbound.id)))}
                      />
                      <span className="min-w-0 flex-1 truncate" dir="auto">
                        {inboundName(inbound)}
                      </span>
                      <span dir="ltr" className="text-caption text-muted-foreground">
                        {inboundAddress(inbound)}
                      </span>
                    </label>
                  </li>
                )
              })}
              {chosen.inbounds.length === 0 && (
                <li className="text-footnote text-muted-foreground sm:col-span-2">
                  این سرور هنوز اینباندی ندارد؛ {serverPages ? 'از صفحه سرور به‌روزرسانی کنید.' : 'از پشتیبانی بخواهید آن را به‌روزرسانی کند.'}
                </li>
              )}
            </ul>
          )}

          <div className="flex justify-end">
            {/* Held, not disabled, while there is nothing to add: just pressed, it keeps the focus. */}
            <Button variant="secondary" size="sm" icon={Plus} onClick={add} aria-disabled={!canAdd}>
              افزودن به پلن
            </Button>
          </div>
        </div>
      ) : (
        <p ref={allAdded} tabIndex={-1} className="rounded-sm text-footnote text-muted-foreground outline-none focus-visible:focus-ring">
          همه سرورها به این پلن اضافه شده‌اند.
        </p>
      )}

      {invalid && (
        <ul id={`${ids}-errors`} className="grid gap-0.5 text-footnote text-danger">
          {errors.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      )}
    </div>
  )
}
