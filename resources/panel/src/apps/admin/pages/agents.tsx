import { useQuery } from '@tanstack/react-query'
import { Info } from 'lucide-react'
import { AgentsList } from '@/apps/admin/agency/agents-list'
import { LevelsList } from '@/apps/admin/agency/levels-list'
import { RequestsList } from '@/apps/admin/agency/requests-list'
import { AgencySettingsCard } from '@/apps/admin/agency/settings-card'
import { AGENCY_SECTIONS } from '@/apps/admin/nav'
import { agencySettingsQuery, agencySummaryQuery } from '@/components/agency/queries'
import { MenuButtonNotice } from '@/components/bot/menu-button-notice'
import { Callout } from '@/components/callout'
import { ErrorState } from '@/components/error-state'
import { useReadFailure } from '@/components/page'
import { ProgramOff } from '@/components/program-off'
import { SectionedPage } from '@/components/sectioned-page'
import { StatCard, StatGrid } from '@/components/stat-card'
import { CountBadge } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import type { AgencySettings, AgencySummary } from '@/lib/api-types'
import { useMainShop } from '@/lib/auth'
import { formatMoney, formatNumber } from '@/lib/format'

type Section = (typeof AGENCY_SECTIONS)[number]['value']

const HEADERS: Record<Section, { title: string; description: string }> = {
  requests: {
    title: 'درخواست‌های نمایندگی',
    description: 'درخواست‌هایی که مشتری‌ها از صفحه «نمایندگی» ربات می‌فرستند؛ با تایید روی یک سطح و با یک اعتبار، نماینده می‌شوند.',
  },
  agents: {
    title: 'نماینده‌ها',
    description: 'ربات هر نماینده و حجمی که برای فروش دارد، فروش رباتش، سطح و اعتبار و موجودی‌اش. فروشگاه هر نماینده را از منوی ردیفش در پنل باز کنید.',
  },
  levels: {
    title: 'سطح‌های نمایندگی',
    description: 'هر سطح یک نام و قیمت هر گیگابایت حجم است؛ نماینده حجمی را که رباتش می‌فروشد با قیمت سطحش می‌خرد.',
  },
  settings: {
    title: 'تنظیمات نمایندگی',
    description:
      'قانون‌های برنامه نمایندگی برای کل فروشگاه: پذیرفتن درخواست تازه، اعتبار اولیه، مدت نمایندگی و کف خرید ماهانه هر نماینده، حجمی که نماینده‌ها از ربات اصلی می‌خرند و هشدار پیش از توقف فروش.',
  },
}

/** The program in a paragraph, behind the header's ⓘ on every section. */
const PROGRAM =
  'مشتری از صفحه «نمایندگی» ربات درخواست می‌دهد؛ با تایید، روی یک سطح نماینده می‌شود و ربات فروش خودش را دارد: توکنش را از ربات اصلی می‌فرستد، پلن‌ها و قیمت‌ها و مشتری‌هایش را در پنل خودش می‌سازد و سرویس‌ها روی سرورهای شما ساخته می‌شوند. حجمی را که رباتش می‌فروشد با قیمت سطحش از شما می‌خرد و تا اعتبارش می‌تواند بدهکار شود.'

/**
 * «نمایندگان» (the owner's): the agency program's numbers above four sections — requests, agents, levels and the rules.
 */
export function AgentsPage() {
  const summaryRead = useQuery(agencySummaryQuery)
  // The program's switch and starting credit are its rules (the settings section reads the same).
  const rulesRead = useQuery(agencySettingsQuery)
  const summary = summaryRead.data?.summary
  const rules = rulesRead.data?.settings
  // The program's menu button is the main bot's: its notice reads the keyboards of the shop the panel shows.
  const mainShop = useMainShop()
  const pending = summary?.pending ?? 0
  // Either read failing leaves the program's state unknown: one word for both, one retry — said while it asks again.
  const summaryFailure = useReadFailure(summaryRead)
  const rulesFailure = useReadFailure(rulesRead)
  const failure = summaryFailure.error ?? rulesFailure.error

  return (
    <SectionedPage
      sections={AGENCY_SECTIONS}
      header={(current) => ({ ...HEADERS[current.value], info: PROGRAM })}
      labels={{
        requests: (
          <span className="flex items-center gap-1.5">
            درخواست‌ها
            {pending > 0 && <CountBadge count={pending} tone="warning" label="در انتظار" />}
          </span>
        ),
      }}
      above={(current) =>
        current.value !== 'settings' && (
          <>
            {rules && !rules.enabled && (
              <ProgramOff to="/agents/settings" action="روشن کردن در تنظیمات نمایندگی">
                نمایندگی خاموش است: درخواست تازه‌ای ثبت نمی‌شود و دکمه نمایندگی برای مشتری‌ها دیده نمی‌شود. نماینده‌ها، ربات‌ها و حسابشان سر جایشان می‌مانند.
              </ProgramOff>
            )}
            {mainShop && rules?.enabled && <MenuButtonNotice action="agency" label="نمایندگی" screen="درخواست نمایندگی و حساب نماینده‌ها" />}
            {summary && summary.levels === 0 && (
              <Callout tone="info" icon={Info}>
                هنوز سطحی تعریف نشده است؛ درخواست‌ها روی یک سطح تایید می‌شوند.{' '}
                {current.value === 'levels' ? (
                  'اولین سطح را بسازید.'
                ) : (
                  <TextLink to="/agents/levels" inline className="font-medium">
                    ساختن اولین سطح
                  </TextLink>
                )}
              </Callout>
            )}
            {failure && (
              <ErrorState
                what="وضعیت نمایندگی"
                error={failure}
                onRetry={() => {
                  if (summaryFailure.error) void summaryRead.refetch()
                  if (rulesFailure.error) void rulesRead.refetch()
                }}
                retrying={summaryFailure.retrying || rulesFailure.retrying}
              />
            )}
            <Numbers summary={summary} rules={rules} loading={summaryRead.isPending} />
          </>
        )
      }
    >
      {{
        requests: <RequestsList />,
        agents: <AgentsList />,
        levels: <LevelsList />,
        settings: (
          <div className="max-w-[48rem]">
            <AgencySettingsCard />
          </div>
        ),
      }}
    </SectionedPage>
  )
}

/** The program's numbers, over every list; `loading` while they are read. */
function Numbers({ summary, rules, loading }: { summary: AgencySummary | undefined; rules: AgencySettings | undefined; loading: boolean }) {
  return (
    <StatGrid label="آمار نمایندگی">
      <StatCard label="نماینده‌ها" value={summary && formatNumber(summary.agents)} hint="مشتری‌هایی که روی یک سطح هستند" loading={loading} />
      <StatCard label="ربات‌های فعال" value={summary && formatNumber(summary.bots)} hint="ربات نماینده‌هایی که توکنشان را فرستاده‌اند" loading={loading} />
      <StatCard label="درخواست‌های در انتظار" value={summary && formatNumber(summary.pending)} hint="منتظر تایید یا رد" loading={loading} />
      <StatCard
        label="سطح‌ها"
        value={summary && formatNumber(summary.levels)}
        hint={rules && (Number(rules.default_credit) > 0 ? `اعتبار اولیه: ${formatMoney(rules.default_credit)}` : 'بدون اعتبار اولیه')}
        loading={loading}
      />
    </StatGrid>
  )
}
