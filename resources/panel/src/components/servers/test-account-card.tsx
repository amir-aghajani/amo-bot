import { useEffect, useRef } from 'react'
import { queryOptions, useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router'
import { ErrorState } from '@/components/error-state'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { ServerTestAccount, ServerTestAccountRequest, ServerTestAccountResponse } from '@/lib/api-types'
import { formatBytes, formatDuration, MEGABYTE, wholeNumberOf } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { useSettingsGroup } from '@/lib/use-settings-group'

/** The card's address on the server page: the tests page's servers link here. */
const ANCHOR = 'test-account'

/** The server's bounds on a test's terms (TestServers): its traffic in MB and its hours. */
const TRAFFIC_MAX_MB = 102_400
const HOURS_MAX = 720

/** A typed term within 1…max, for its hint to word; null otherwise, and the hint says the bounds. */
function termOf(typed: string, max: number): number | null {
  const number = wholeNumberOf(typed)
  return number !== null && number >= 1 && number <= max ? number : null
}

const testAccountQuery = (id: number) =>
  queryOptions({ queryKey: queryKeys.serverTestAccount(id), queryFn: () => api.get<ServerTestAccountResponse>(`/servers/${id}/test-account`).then((data) => data.test_account) })

/**
 * A server's free test accounts («اکانت تست»), saved apart from its connection: whether it gives them, a test's traffic
 * and hours, and whether the hours count from the first connection. Opened at `#test-account`, the page scrolls to it.
 */
export function TestAccountCard({ serverId }: { serverId: number }) {
  const read = useQuery(testAccountQuery(serverId))
  const ref = useRef<HTMLDivElement>(null)
  const { hash } = useLocation()
  const drawn = read.data !== undefined

  // The card loads after the page: the browser's own jump to the fragment came before it was there.
  useEffect(() => {
    if (drawn && hash === `#${ANCHOR}`) ref.current?.scrollIntoView({ block: 'start' })
  }, [drawn, hash])

  return (
    <div id={ANCHOR} ref={ref}>
      {read.data ? (
        <TestAccountForm key={serverId} serverId={serverId} terms={read.data} />
      ) : read.error ? (
        <ErrorState what="اکانت تست سرور" error={read.error} onRetry={() => void read.refetch()} retrying={read.isFetching} />
      ) : (
        <Skeleton className="h-64 w-full rounded-xl" />
      )}
    </div>
  )
}

function TestAccountForm({ serverId, terms }: { serverId: number; terms: ServerTestAccount }) {
  const group = useSettingsGroup({
    save: (values) => api.put(`/servers/${serverId}/test-account`, values),
    queryKey: queryKeys.serverTestAccount(serverId),
    apply: (_current: ServerTestAccount | undefined, result: ServerTestAccountResponse) => result.test_account,
    initial: {
      test_enabled: terms.test_enabled,
      test_traffic_mb: String(terms.test_traffic_mb),
      test_duration_hours: String(terms.test_duration_hours),
      test_on_hold: terms.test_on_hold,
    } satisfies ServerTestAccountRequest,
    saved: 'اکانت تست سرور ذخیره شد',
    // The tests page's servers show the terms, and whether the server gives a test now.
    invalidates: [queryKeys.testServers],
  })
  const { values, set, error } = group
  const megabytes = termOf(values.test_traffic_mb, TRAFFIC_MAX_MB)
  const hours = termOf(values.test_duration_hours, HOURS_MAX)

  return (
    <SectionCard
      form={group}
      title="اکانت تست"
      description="اکانت تست رایگانی که مشتری‌ها روی این سرور می‌گیرند، در همه فروشگاه‌ها از جمله نماینده‌ها. اکانت روی همه اینباندهایی که این سرور می‌فروشد ساخته می‌شود؛ اکانت‌هایی که قبلا داده شده‌اند شرایط خودشان را نگه می‌دارند."
    >
      <SwitchRow
        label="اکانت تست روی این سرور"
        hint={
          <>
            روشن که باشد، مشتری‌های هر فروشگاهی که اکانت تست را در{' '}
            <TextLink to="/tests/settings" inline>
              اکانت تست › تنظیمات
            </TextLink>{' '}
            خودش روشن کرده، روی این سرور اکانت تست می‌گیرند.
          </>
        }
        checked={values.test_enabled}
        onCheckedChange={(checked) => set('test_enabled', checked)}
        error={error('test_enabled')}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="test_traffic_mb"
          label="حجم (مگابایت)"
          error={error('test_traffic_mb')}
          hint={megabytes !== null ? `هر اکانت تست ${formatBytes(megabytes * MEGABYTE)}` : `بین 1 تا ${TRAFFIC_MAX_MB} مگابایت`}
        >
          <Input dir="ltr" inputMode="numeric" value={values.test_traffic_mb} onChange={(e) => set('test_traffic_mb', e.target.value)} />
        </Field>
        <Field id="test_duration_hours" label="مدت (ساعت)" error={error('test_duration_hours')} hint={hours !== null ? `هر اکانت تست ${formatDuration(hours)}` : `بین 1 تا ${HOURS_MAX} ساعت`}>
          <Input dir="ltr" inputMode="numeric" value={values.test_duration_hours} onChange={(e) => set('test_duration_hours', e.target.value)} />
        </Field>
      </div>
      <SwitchRow
        label="شروع از اولین اتصال"
        hint="روشن: ساعت‌ها از اولین اتصال مشتری شمرده می‌شوند. خاموش: از همان لحظه که اکانت تست ساخته می‌شود."
        checked={values.test_on_hold}
        onCheckedChange={(checked) => set('test_on_hold', checked)}
        error={error('test_on_hold')}
      />
    </SectionCard>
  )
}
