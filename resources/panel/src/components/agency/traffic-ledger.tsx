import type { QueryKey } from '@tanstack/react-query'
import { HardDrive } from 'lucide-react'
import { EmptyState } from '@/components/empty-state'
import { LedgerList } from '@/components/ledger-list'
import { ListView } from '@/components/list-view'
import { Reviewer } from '@/components/reviewer'
import { api } from '@/lib/api'
import type { TrafficLine, TrafficLinesResponse } from '@/lib/api-types'
import { formatBytes, formatDate } from '@/lib/format'
import { usePagedList } from '@/lib/use-paged-list'

/** What a line of an agent's traffic was, in the panel's words. */
const TYPES: Record<TrafficLine['type'], string> = {
  purchase: 'خرید حجم',
  sale: 'فروش',
  renewal: 'تمدید',
  extension: 'افزایش حجم',
  refund: 'بازگشت حجم',
  adjust: 'اصلاح پشتیبانی',
}

/**
 * An agent's traffic, line by line, newest first — bought from the main bot, drawn by a sale or a renewal of their bot or
 * by the traffic given one of its services, given back when a delivery or an extension failed, set right by the shop —
 * with what was left after each. Paged.
 */
export function TrafficLedger({ queryKey, url }: { queryKey: QueryKey; url: string }) {
  const list = usePagedList({ queryKey, read: (query) => api.get<TrafficLinesResponse>(url, query), list: 'lines' })

  return (
    <ListView
      list={list}
      noun="گردش حجم"
      unit="مورد"
      skeletonRows={3}
      empty={<EmptyState compact framed icon={HardDrive} title="هنوز گردشی ندارد" description="خرید حجم، فروش، تمدید و افزایش حجم سرویس‌های ربات این‌جا ثبت می‌شوند." />}
    >
      <LedgerList
        entries={list.rows.map((line) => ({
          id: line.id,
          credit: line.bytes > 0,
          title: (
            <>
              {TYPES[line.type]}
              {line.description && <span className="text-muted-foreground"> · {line.description}</span>}
            </>
          ),
          detail: (
            <>
              {formatDate(line.created_at)} · مانده {formatBytes(line.balance_after)}
              {line.reviewer && (
                <>
                  {' · '}
                  <Reviewer name={line.reviewer} />
                </>
              )}
            </>
          ),
          amount: formatBytes(Math.abs(line.bytes)),
        }))}
      />
    </ListView>
  )
}
