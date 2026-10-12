import { useQuery } from '@tanstack/react-query'
import { Server as ServerIcon } from 'lucide-react'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowTitleLink } from '@/components/list-view'
import { StatusBadge } from '@/components/status-badge'
import { testServersQuery } from '@/components/tests/queries'
import { TestTerms } from '@/components/tests/test-terms'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { TestServerRow } from '@/lib/api-types'
import { MEGABYTE } from '@/lib/format'
import type { Status } from '@/lib/statuses'
import { cn } from '@/lib/utils'

const GIVES: Status = { label: 'اکانت تست می‌دهد', tone: 'success' }
const NOT_NOW: Status = { label: 'فعلا نمی‌دهد', tone: 'warning' }
const OFF: Status = { label: 'خاموش', tone: 'neutral' }

/** Whether the server would give a test now: switched on and able to, switched on but not able to now, or switched off. */
function givingOf(server: TestServerRow): Status {
  return server.available ? GIVES : server.test_enabled ? NOT_NOW : OFF
}

/**
 * Every server with what a test on it gets and whether it would give one in the open shop now — the shop's own switch
 * aside, which the page says above. Where the panel has a server's page (the owner's), each name leads to its test card;
 * elsewhere the list only reads.
 */
export function TestServersList({ serverPage }: { serverPage?: (id: number) => string }) {
  // Read while hidden too: the page reads it for its notices in every section anyway.
  const read = useQuery(testServersQuery)
  const servers = read.data ?? []
  const list = { rows: servers, isPending: read.isPending, error: read.error, refetch: () => void read.refetch() }

  return (
    <ListView list={list} noun="سرورها" empty={<EmptyState framed icon={ServerIcon} title="هنوز سروری نیست" description="اکانت تست روی سرورهای فروشگاه ساخته می‌شود؛ اول سروری اضافه شود." />}>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>سرور</TableHead>
            <TableHead>هر اکانت تست</TableHead>
            <TableHead>الان</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {servers.map((server) => (
            <TableRow key={server.id} className={cn(!server.test_enabled && 'text-muted-foreground')}>
              <TableCell>{serverPage ? <RowTitleLink to={serverPage(server.id)}>{server.name}</RowTitleLink> : <bdi className="font-medium">{server.name}</bdi>}</TableCell>
              <TableCell>
                <TestTerms bytes={server.test_traffic_mb * MEGABYTE} hours={server.test_duration_hours} onHold={server.test_on_hold} />
              </TableCell>
              <TableCell>
                <StatusBadge status={givingOf(server)} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </ListView>
  )
}
