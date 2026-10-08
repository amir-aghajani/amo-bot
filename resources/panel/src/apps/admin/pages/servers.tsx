import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Plus, Server as ServerIcon } from 'lucide-react'
import { useNavigate } from 'react-router'
import { EmptyState } from '@/components/empty-state'
import { Dash, ListView, RowTitleLink } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { AddServerModal } from '@/components/servers/add-server-modal'
import { serversQuery } from '@/components/servers/queries'
import { ServerStatus, UnsellableNote } from '@/components/servers/server-status'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatNumber, timeAgo } from '@/lib/format'

/** The panels the shop sells on; each opens on its own page. */
export function ServersPage() {
  const [adding, setAdding] = useState(false)
  const navigate = useNavigate()
  const read = useQuery(serversQuery)
  const servers = read.data?.servers ?? []
  const list = { rows: servers, isPending: read.isPending, error: read.error, refetch: () => void read.refetch() }

  const add = (variant: 'default' | 'secondary') => (
    <Button variant={variant} icon={Plus} onClick={() => setAdding(true)}>
      افزودن سرور
    </Button>
  )

  return (
    <Page>
      <PageHeader
        title="سرورها"
        // Counted once the list was read — never a zero it does not know.
        count={read.isSuccess ? servers.length : undefined}
        description="پنل‌هایی که کانفیگ‌ها روی آن‌ها ساخته می‌شوند؛ هر سرور را باز کنید تا اینباندها، اتصال و افزودن زمان و حجم را ببینید."
        actions={add('default')}
      />

      <ListView
        list={list}
        noun="سرورها"
        empty={<EmptyState framed icon={ServerIcon} title="هنوز سروری اضافه نشده است" description="اولین پنل را وصل کنید تا بتوانید پلن بسازید و اشتراک بفروشید." action={add('secondary')} />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>سرور</TableHead>
              <TableHead>وضعیت</TableHead>
              <TableHead className="hidden md:table-cell">اینباندها</TableHead>
              <TableHead className="hidden sm:table-cell">اشتراک‌های فعال</TableHead>
              <TableHead className="hidden text-end lg:table-cell">آخرین بررسی</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {servers.map((server) => (
              <TableRow key={server.id}>
                <TableCell>
                  <div className="grid gap-1">
                    <RowTitleLink to={`/servers/${server.id}`}>{server.name}</RowTitleLink>
                    <span className="flex items-center gap-2 text-footnote text-muted-foreground">
                      <Badge variant="outline" dir="ltr">
                        {server.driver_label}
                      </Badge>
                      <span className="max-w-56 truncate text-caption" dir="ltr">
                        {server.base_url}
                      </span>
                    </span>
                    <UnsellableNote server={server} />
                  </div>
                </TableCell>
                <TableCell>
                  <ServerStatus server={server} details />
                </TableCell>
                <TableCell className="hidden text-muted-foreground tabular md:table-cell">
                  <span className="text-foreground">{formatNumber(server.counts.selectable_inbounds)}</span> از {formatNumber(server.counts.inbounds)} برای فروش
                </TableCell>
                <TableCell className="hidden tabular sm:table-cell">
                  {formatNumber(server.counts.active_subscriptions)}
                  {server.capacity != null && <span className="text-muted-foreground"> / {formatNumber(server.capacity)}</span>}
                </TableCell>
                <TableCell className="hidden text-end text-muted-foreground lg:table-cell">{server.last_checked_at ? timeAgo(server.last_checked_at) : <Dash />}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <AddServerModal
        open={adding}
        onClose={() => setAdding(false)}
        onCreated={(server, probe) => {
          setAdding(false)
          // The new server's page shows what its panel answered as it was added.
          void navigate(`/servers/${server.id}`, { state: { probe } })
        }}
      />
    </Page>
  )
}
