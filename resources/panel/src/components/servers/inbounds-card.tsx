import { RefreshCw } from 'lucide-react'
import { EmptyState } from '@/components/empty-state'
import { Dash } from '@/components/list-view'
import type { ServerPage } from '@/components/servers/use-server'
import { StatusDot } from '@/components/status-badge'
import { Switch } from '@/components/switch'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { ServerInboundRow } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

/** An inbound as the admin knows it: its remark, else its tag. */
const inboundName = (inbound: ServerInboundRow) => inbound.remark || inbound.tag

/** What a server sells: its inbounds as the panel last listed them, each with its switch for sale. */
export function InboundsCard({ page, inbounds }: { page: ServerPage; inbounds: ServerInboundRow[] }) {
  const selling = inbounds.filter((inbound) => inbound.is_selectable && inbound.enabled).length

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>اینباندها</CardTitle>
          <CardDescription>{inbounds.length === 0 ? 'هنوز از پنل خوانده نشده است.' : `${formatNumber(selling)} از ${formatNumber(inbounds.length)} اینباند برای فروش انتخاب شده است.`}</CardDescription>
        </CardHeading>
        <CardAction>
          <Button variant="secondary" size="sm" icon={RefreshCw} busy={page.sync.isPending} onClick={() => page.sync.mutate()}>
            به‌روزرسانی از پنل
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent>
        {inbounds.length === 0 ? (
          <EmptyState compact title="اینباندی ثبت نشده است" description="با «به‌روزرسانی از پنل» لیست اینباندها را بگیرید و آن‌هایی را که می‌خواهید بفروشید علامت بزنید." />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>اینباند</TableHead>
                <TableHead>پروتکل</TableHead>
                <TableHead className="hidden sm:table-cell">پورت</TableHead>
                <TableHead className="hidden md:table-cell">کلاینت‌ها</TableHead>
                <TableHead className="text-end">فروش</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {inbounds.map((inbound) => (
                <TableRow key={inbound.id} className={cn(!inbound.enabled && 'text-muted-foreground')}>
                  <TableCell>
                    <div className="flex items-center gap-2.5">
                      <StatusDot tone={inbound.enabled ? 'success' : 'neutral'} />
                      <div className="grid">
                        <span className="font-medium" dir="auto">
                          {inboundName(inbound)}
                        </span>
                        <span className="text-caption text-faint" dir="ltr">
                          #{inbound.remote_key} · {inbound.tag}
                        </span>
                      </div>
                      <span className="sr-only">{inbound.enabled ? 'فعال در پنل' : 'غیرفعال در پنل'}</span>
                    </div>
                  </TableCell>
                  <TableCell>
                    {/* A PasarGuard group is no one protocol and has no port of its own. */}
                    {inbound.protocol === null ? (
                      <Dash />
                    ) : (
                      <span className="text-footnote" dir="ltr">
                        {inbound.protocol}
                        {inbound.network && `/${inbound.network}`}
                        {inbound.security && inbound.security !== 'none' && <span className="text-muted-foreground">+{inbound.security}</span>}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="hidden sm:table-cell">
                    {inbound.port === null ? (
                      <Dash />
                    ) : (
                      <bdi dir="ltr" className="text-footnote">
                        {inbound.port}
                      </bdi>
                    )}
                  </TableCell>
                  <TableCell className="hidden tabular md:table-cell">{formatNumber(inbound.client_count)}</TableCell>
                  <TableCell className="text-end">
                    {/* One change at a time — each answer is the whole list —, the switch pressed keeping the focus meanwhile. */}
                    <Switch
                      checked={inbound.is_selectable}
                      disabled={!inbound.enabled}
                      held={page.sell.isPending}
                      onCheckedChange={(selectable) => page.sell.mutate({ inbound, selectable })}
                      aria-label={`فروش روی ${inboundName(inbound)}`}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  )
}
