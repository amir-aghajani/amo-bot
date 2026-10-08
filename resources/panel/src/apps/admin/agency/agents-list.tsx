import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Handshake, HardDrive, Pencil, Search, Store, UserX } from 'lucide-react'
import { toast } from 'sonner'
import { useOpenShop } from '@/apps/admin/owner'
import { AgentBotName } from '@/components/agency/agent-bot-name'
import { AgentModal, TrafficModal } from '@/components/agency/agent-modal'
import { agencyLevelsQuery } from '@/components/agency/queries'
import { CUSTOMER_NOTE } from '@/components/agency/reject-modal'
import { Balance } from '@/components/balance'
import { ConfirmModal } from '@/components/confirm-modal'
import { EmptyState } from '@/components/empty-state'
import { FilterSelect } from '@/components/filter-select'
import { Dash, ListView, RowMenu, RowTitleButton, SortableHead } from '@/components/list-view'
import { SearchBox } from '@/components/search-box'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { distinctUserLabel, UserIdentity, userLabel, userPage } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { AgentRow, AgentSort, AgentsResponse } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { messageOf } from '@/lib/failure'
import { formatBytes, formatMoney, formatNumber, formatPricePerGb } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { usePagedList } from '@/lib/use-paged-list'

/** What the headers sort the agents by — the list's own order, the newest first, leads. */
const SORTS: AgentSort[] = ['joined', 'balance', 'traffic', 'sold']

/**
 * The agents, by level or all — each name the way to their page as a customer —: their bot and its traffic, what it
 * sold, what they owe; their level and credit, their traffic set right, their shop opened in the panel, or the end of
 * their agency.
 */
export function AgentsList() {
  const list = usePagedList({
    queryKey: queryKeys.agents,
    read: (query) => api.get<AgentsResponse>('/agency/agents', query),
    list: 'agents',
    params: ['level'],
    sorts: SORTS,
    address: '/agents/list',
  })
  const levels = useQuery(agencyLevelsQuery)
  const [editing, setEditing] = useState<AgentRow | null>(null)
  const [traffic, setTraffic] = useState<AgentRow | null>(null)
  const [revoking, setRevoking] = useState<AgentRow | null>(null)
  // An agent's shop, at its address: opened here (every unsaved draft dropped — the program's settings on this page's
  // other section too —, asked first), or in a new tab as any link.
  const { link: shopLink } = useOpenShop()
  // An agent is a customer of the main bot: their page is there, while the main bot's shop is the one open.
  const mainShop = useMainShop()

  // Refused (a note too long, ended meanwhile), the dialog says why. The program's numbers and each level's count one
  // agent fewer.
  const revoke = useMutation({
    mutationFn: ({ agent, note }: { agent: AgentRow; note: string }) => api.post(`/agency/agents/${agent.id}/revoke`, { note }),
    onSuccess: (_result, { agent }) => {
      toast.success('نمایندگی لغو شد')
      list.remove(agent.id)
      setRevoking(null)
    },
    meta: { quiet: true, invalidates: [queryKeys.agency, queryKeys.agencyLevels] },
  })

  return (
    <>
      <div className="flex flex-wrap items-center gap-2">
        <SearchBox value={list.typed} onChange={list.setTyped} placeholder="نام، نام کاربری یا شناسه تلگرام" aria-label="جستجوی نماینده" />
        <FilterSelect
          label="سطح"
          value={list.params.level ?? ''}
          options={[{ value: '', label: 'همه' }, ...(levels.data?.levels ?? []).map((level) => ({ value: String(level.id), label: level.name }))]}
          onChange={(value) => list.setParam('level', value)}
        />
      </div>
      <ListView
        list={list}
        noun="نماینده‌ها"
        unit="نماینده"
        empty={<EmptyState framed icon={Handshake} title="هنوز نماینده‌ای ندارید" description="با تایید اولین درخواست نمایندگی، نماینده این‌جا می‌آید." />}
        noMatch={<EmptyState framed icon={Search} title="نماینده‌ای پیدا نشد" description="با این جستجو یا سطح کسی نیست." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>نماینده</TableHead>
              <SortableHead list={list} by="traffic" label="حجم ربات">
                ربات
              </SortableHead>
              <TableHead>سطح</TableHead>
              <SortableHead list={list} by="balance" className="hidden md:table-cell">
                موجودی
              </SortableHead>
              <SortableHead list={list} by="sold" className="hidden lg:table-cell">
                فروش ربات
              </SortableHead>
              <TableHead className="w-10">
                <span className="sr-only">عملیات</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((agent) => (
              <TableRow key={agent.id}>
                <TableCell>
                  <UserIdentity user={agent.user} status={agent.status} to={mainShop ? userPage(agent.id) : undefined} />
                </TableCell>
                <TableCell>
                  {agent.bot ? (
                    <div className="grid">
                      <AgentBotName bot={agent.bot} /> <span className="text-footnote text-muted-foreground">حجم: {formatBytes(agent.bot.traffic_balance)}</span>
                    </div>
                  ) : (
                    <Dash />
                  )}
                </TableCell>
                <TableCell>
                  <RowTitleButton onClick={() => setEditing(agent)}>{agent.level.name}</RowTitleButton>
                  <span className="block text-footnote text-muted-foreground">{formatPricePerGb(agent.level.price_per_gb)}</span>
                </TableCell>
                <TableCell className="hidden whitespace-nowrap md:table-cell">
                  <div className="grid">
                    <Balance balance={agent.balance} />
                    {Number(agent.credit_limit) > 0 && <span className="text-footnote text-muted-foreground">اعتبار {formatMoney(agent.credit_limit)}</span>}
                  </div>
                </TableCell>
                <TableCell className="hidden lg:table-cell">
                  <div className="grid">
                    <span className="whitespace-nowrap tabular">{formatNumber(agent.counts.sold)} سرویس</span>
                    <span className="text-footnote text-muted-foreground">
                      {formatNumber(agent.counts.active)} فعال · {formatNumber(agent.counts.customers)} مشتری
                    </span>
                  </div>
                </TableCell>
                <TableCell>
                  <RowMenu label={distinctUserLabel(agent.user)}>
                    <DropdownMenuItem onSelect={() => setEditing(agent)}>
                      <Pencil aria-hidden />
                      سطح و اعتبار
                    </DropdownMenuItem>
                    {agent.bot && (
                      <DropdownMenuItem onSelect={() => setTraffic(agent)}>
                        <HardDrive aria-hidden />
                        حجم
                      </DropdownMenuItem>
                    )}
                    {agent.bot && (
                      <DropdownMenuItem asChild>
                        <a {...shopLink(agent.bot.id)}>
                          <Store aria-hidden />
                          باز کردن فروشگاه
                        </a>
                      </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" onSelect={() => setRevoking(agent)}>
                      <UserX aria-hidden />
                      لغو نمایندگی
                    </DropdownMenuItem>
                  </RowMenu>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <AgentModal
        agent={editing}
        onClose={() => setEditing(null)}
        onSaved={(agent) => {
          list.replace(agent)
          setEditing(null)
        }}
      />
      <TrafficModal
        agent={traffic}
        onClose={() => setTraffic(null)}
        onSaved={(agent) => {
          list.replace(agent)
          setTraffic(agent)
        }}
      />
      <ConfirmModal
        open={revoking !== null}
        onClose={() => {
          revoke.reset()
          setRevoking(null)
        }}
        title="لغو نمایندگی"
        description={
          revoking
            ? `ربات ${userLabel(revoking.user)} خاموش می‌شود و ورودش به پنل تمام می‌شود؛ اگر بدهکار باشد، بدهی‌اش می‌ماند. فروشگاه و حجمش نگه داشته می‌شود و با نماینده شدن دوباره برمی‌گردد. به او خبر داده می‌شود.`
            : undefined
        }
        confirmLabel="لغو نمایندگی"
        destructive
        pending={revoke.isPending}
        note={CUSTOMER_NOTE}
        error={revoke.error && messageOf(revoke.error, 'note', 'status')}
        onConfirm={(note) => revoking && revoke.mutate({ agent: revoking, note })}
      />
    </>
  )
}
