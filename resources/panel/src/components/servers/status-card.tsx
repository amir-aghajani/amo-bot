import { TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { isSecretState } from '@/components/driver-form/draft'
import { Fact, FactList } from '@/components/fact-list'
import { ProbeResult } from '@/components/servers/probe-result'
import { ServerStatus } from '@/components/servers/server-status'
import { TextLink } from '@/components/text-link'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import type { DriverDescription, DriverValues, Probe, ServerRow } from '@/lib/api-types'
import { useMainShop, useSession } from '@/lib/auth'
import { formatDate, formatNumber, timeAgo } from '@/lib/format'

/**
 * What the card says of a server's connection, read off its form (ServerRow.form) by the fields every connector shares
 * (PanelConnection, PanelCredentials on the PHP side): the way in — and a two-factor secret kept, a connector's own —,
 * the subscription links' prefix, the TLS check, the timeout.
 */
function connectionOf(form: DriverValues) {
  const text = (name: string) => {
    const value = form[name]
    return typeof value === 'string' ? value : ''
  }
  const secret = form.totp_secret

  return {
    token: form.auth_mode === 'token',
    username: text('username'),
    totp: isSecretState(secret) && secret.set,
    subscriptionUrl: text('subscription_url'),
    verifyTls: form.verify_tls === true,
    timeout: typeof form.timeout === 'number' ? form.timeout : null,
  }
}

/**
 * What the connector calls a way in — its own word on its form (the choices of `auth_mode`): a 3x-ui's «توکن API», a
 * PasarGuard's «کلید API»; none until its description is read.
 */
function wayIn(driver: DriverDescription | undefined, mode: string): string | undefined {
  return driver?.fields.find((field) => field.name === 'auth_mode')?.options.find((option) => option.value === mode)?.label
}

/**
 * Where a server stands: its connection and the last check's answer, whether it can sell, and its facts — the connection
 * in its connector's words (`driver`, as GET /servers/drivers describes it).
 */
export function StatusCard({ server, probe, driver }: { server: ServerRow; probe: Probe | null; driver?: DriverDescription }) {
  // None while this installation has not its connector: nothing of its connection to say.
  const connection = server.form && connectionOf(server.form)
  const token = wayIn(driver, 'token')

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle>وضعیت</CardTitle>
          <CardDescription>{server.last_checked_at ? `آخرین بررسی ${timeAgo(server.last_checked_at)} · ${formatDate(server.last_checked_at)}` : 'هنوز بررسی نشده است.'}</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="grid gap-4">
        <ServerStatus server={server} />
        {/* A fresh check says it all; until then, what the last one left. */}
        {probe ? (
          <ProbeResult probe={probe} />
        ) : (
          server.last_error && (
            <Callout tone="danger" className="text-footnote wrap-anywhere">
              <span dir="auto">{server.last_error}</span>
            </Callout>
          )
        )}
        {server.unsellable_reason && (
          <Callout tone="warning" icon={TriangleAlert} className="text-footnote">
            فعلا چیزی روی این سرور فروخته یا به آن منتقل نمی‌شود: {server.unsellable_reason}
          </Callout>
        )}
        <FactList className="grid-cols-[minmax(5.5rem,auto)_1fr] gap-x-4 gap-y-2 text-footnote">
          {/* A token's word is its connector's: said once its description is read. */}
          {connection && (!connection.token || token) && (
            <Fact label="احراز هویت">
              {connection.token ? (
                token
              ) : (
                <>
                  نام کاربری
                  {connection.username && (
                    <>
                      {' ('}
                      <bdi dir="ltr">{connection.username}</bdi>)
                    </>
                  )}
                  {connection.totp ? ' + TOTP' : ''}
                </>
              )}
            </Fact>
          )}
          <Fact label="لینک اشتراک">
            {server.serves_subscriptions ? (
              connection?.subscriptionUrl ? (
                <span className="break-all" dir="ltr">
                  {connection.subscriptionUrl}
                </span>
              ) : (
                'از تنظیمات پنل'
              )
            ) : server.serves_subscriptions === false ? (
              <span className="text-danger">در پنل فعال نیست</span>
            ) : (
              <span className="text-warning">نامشخص — سرور را بررسی کنید</span>
            )}
          </Fact>
          <Fact label="اشتراک‌های فعال">
            <ActiveServices server={server} />
          </Fact>
          {connection && <Fact label="گواهی TLS">{connection.verifyTls ? 'بررسی می‌شود' : 'بررسی نمی‌شود'}</Fact>}
          {connection?.timeout != null && (
            <Fact label="تایم‌اوت">
              <span className="tabular">{formatNumber(connection.timeout)} ثانیه</span>
            </Fact>
          )}
          <Fact label="افزوده‌شده">{formatDate(server.created_at, { dateStyle: 'medium' })}</Fact>
        </FactList>
      </CardContent>
    </Card>
  )
}

/**
 * Its running services, every shop's — what its capacity counts —, and of them the open shop's: the ones its
 * subscriptions screen lists, where they are selected and moved to another server. The link says whose they are when
 * other shops — the agents' bots, or the main one and other agents' from an agent's shop — have services there too.
 */
function ActiveServices({ server }: { server: ServerRow }) {
  const shop = useSession().shop
  const mainShop = useMainShop()
  const { active_subscriptions: all, shop_active_subscriptions: here } = server.counts
  const others = all - here
  const list = `/subscriptions?server=${server.id}&status=active`
  const capacity = server.capacity != null && <span className="text-muted-foreground"> از {formatNumber(server.capacity)}</span>

  if (others === 0) {
    return (
      <span>
        <TextLink to={list} className="tabular">
          {formatNumber(all)}
        </TextLink>
        {capacity}
      </span>
    )
  }

  const ours = (
    <>
      {formatNumber(here)} در «<bdi>{shop.name}</bdi>»
    </>
  )

  return (
    <span className="grid gap-0.5">
      <span>
        <span className="tabular">{formatNumber(all)}</span>
        {capacity}
      </span>
      <span className="text-muted-foreground">
        {here > 0 ? (
          <TextLink to={list} className="tabular">
            {ours}
          </TextLink>
        ) : (
          <span className="tabular">{ours}</span>
        )}
        {' · '}
        <span className="tabular">
          {formatNumber(others)} در {mainShop ? 'فروشگاه نماینده‌ها' : 'فروشگاه‌های دیگر'}
        </span>
      </span>
    </span>
  )
}
