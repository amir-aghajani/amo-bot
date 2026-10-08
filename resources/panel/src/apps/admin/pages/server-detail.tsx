import { useEffect, useState } from 'react'
import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { PlugZap } from 'lucide-react'
import { useLocation, useNavigate, useParams } from 'react-router'
import { BackLink } from '@/components/back-link'
import { ErrorState } from '@/components/error-state'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { DeleteCard } from '@/components/servers/delete-card'
import { GrantsCard } from '@/components/servers/grants-card'
import { InboundsCard } from '@/components/servers/inbounds-card'
import { serverDriversQuery } from '@/components/servers/queries'
import { ServerForm } from '@/components/servers/server-form'
import { StatusCard } from '@/components/servers/status-card'
import { useServer, type ServerPage } from '@/components/servers/use-server'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { DriverDescription, Probe, ServerRow } from '@/lib/api-types'
import { useTitleSubject } from '@/lib/document-title'

/** What the panel answered as the server was added — the servers page hands it over in the history entry it opens. */
function createdProbe(state: unknown): Probe | null {
  return typeof state === 'object' && state !== null && 'probe' in state ? (state.probe as Probe) : null
}

/** One server: its inbounds (what is sold), its connection, its state and checks, the gifts to its services, and deleting it. */
export function ServerDetailPage() {
  const id = Number(useParams().id)
  const location = useLocation()
  const navigate = useNavigate()
  const [created] = useState(() => createdProbe(location.state))
  const page = useServer(id, created)
  const { detail, check } = page
  // The connectors as they describe themselves: the connection's form, and the words the status card says of it.
  const drivers = useQuery(serverDriversQuery)
  useTitleSubject(detail.data?.server.name)

  // Shown once: the entry forgets it, so going back to the page (or reloading it) does not show a stale answer.
  useEffect(() => {
    if (location.state !== null) void navigate(location.pathname, { replace: true, state: null })
  }, [location.pathname, location.state, navigate])

  if (detail.isPending) {
    return (
      <Page width="default">
        <BackLink to="/servers">سرورها</BackLink>
        <Skeleton className="h-9 w-64" />
        <div className="grid gap-4 xl:grid-cols-3">
          <Skeleton className="h-80 w-full rounded-xl xl:col-span-2" />
          <Skeleton className="h-80 w-full rounded-xl" />
        </div>
      </Page>
    )
  }

  if (detail.error) {
    return (
      <Page width="default">
        <BackLink to="/servers">سرورها</BackLink>
        <PageHeader title="سرور" />
        <ErrorState what="سرور" error={detail.error} onRetry={() => void detail.refetch()} retrying={detail.isFetching} />
      </Page>
    )
  }

  const { server, inbounds } = detail.data
  const driver = drivers.data?.find((item) => item.key === server.driver)

  return (
    <Page width="default">
      <div className="grid gap-3">
        <BackLink to="/servers">سرورها</BackLink>
        <PageHeader
          title={
            <span className="flex flex-wrap items-center gap-2">
              {server.name}
              <Badge variant="outline" dir="ltr">
                {server.driver_label}
              </Badge>
            </span>
          }
          description={
            <span className="text-footnote" dir="ltr">
              {server.base_url}
            </span>
          }
          actions={
            <Button variant="secondary" icon={PlugZap} busy={check.isPending} onClick={() => check.mutate()}>
              بررسی اتصال
            </Button>
          }
        />
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <div className="grid min-w-0 content-start gap-4 xl:col-span-2">
          <InboundsCard page={page} inbounds={inbounds} />
          <ConnectionCard page={page} server={server} drivers={drivers} driver={driver} />
        </div>

        <div className="grid min-w-0 content-start gap-4">
          <StatusCard server={server} probe={page.probe} driver={driver} />
          <GrantsCard server={server} />
          <DeleteCard page={page} server={server} />
        </div>
      </div>
    </Page>
  )
}

/** The connection's settings: the server's form on its connector (`driver`), as the connector describes it. */
function ConnectionCard({ page, server, drivers, driver }: { page: ServerPage; server: ServerRow; drivers: UseQueryResult<DriverDescription[]>; driver: DriverDescription | undefined }) {
  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>تنظیمات اتصال</CardTitle>
          <CardDescription>فیلدهای محرمانه را برای نگه‌داشتن مقدار فعلی خالی بگذارید.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent>
        {driver ? (
          <ServerForm driver={driver} server={server} onSaved={page.saved} />
        ) : drivers.isPending ? (
          <Skeleton className="h-40 w-full" />
        ) : drivers.error ? (
          <ErrorState what="کانکتورها" error={drivers.error} onRetry={() => void drivers.refetch()} retrying={drivers.isFetching} />
        ) : (
          <p className="text-body text-muted-foreground">فرم تنظیمات این کانکتور در این نسخه در دسترس نیست.</p>
        )}
      </CardContent>
    </Card>
  )
}
