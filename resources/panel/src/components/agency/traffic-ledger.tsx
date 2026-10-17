import type { QueryKey } from '@tanstack/react-query'
import { HardDrive } from 'lucide-react'
import { EmptyState } from '@/components/empty-state'
import { LedgerList } from '@/components/ledger-list'
import { ListView } from '@/components/list-view'
import { Reviewer } from '@/components/reviewer'
import { api } from '@/lib/api'
import type { TrafficLine, TrafficLinesResponse } from '@/lib/api-types'
import { isolateIds } from '@/lib/direction'
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

/** An agent's traffic ledger, newest first: purchases, sales, renewals, extensions, refunds and adjustments, with the balance after each. */
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
              {/* The shop's own notes name orders by number («فروش · سفارش #33»): each kept whole. */}
              {line.description && <span className="text-muted-foreground"> · {isolateIds(line.description)}</span>}
            </>
          ),
          detail: (
            <>
              {formatDate(line.created_at)} · مانده {formatBytes(line.balance_after)}
              {line.reviewer && (
                <>
                  {' · '}
                  <Reviewer name={line.reviewer} person={line.reviewer_name} />
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
