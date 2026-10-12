import { useQuery } from '@tanstack/react-query'
import { MenuButtonNotice } from '@/components/bot/menu-button-notice'
import { WithBotSettings } from '@/components/bot/settings/with-bot-settings'
import { ProgramOff } from '@/components/program-off'
import { SectionedPage } from '@/components/sectioned-page'
import { TEST_SECTIONS } from '@/components/shell/nav'
import { testServersQuery } from '@/components/tests/queries'
import { TestServersList } from '@/components/tests/test-servers-list'
import { TestSettingsCard } from '@/components/tests/test-settings-card'
import { TestsList } from '@/components/tests/tests-list'
import { botSettingsQuery } from '@/lib/queries'

type Section = (typeof TEST_SECTIONS)[number]['value']

const HEADERS: Record<Section, { title: string; description: string }> = {
  tests: {
    title: 'اکانت‌های تست',
    description: 'هر اکانت تستی که مشتری‌ها گرفته‌اند، تازه‌ترین اول: حجم و مدتش، وضعیتش و این‌که مشتری بعد از آن خرید کرد یا نه.',
  },
  servers: {
    title: 'سرورهای اکانت تست',
    description:
      'حجم و مدت اکانت تست هر سرور و این‌که خود سرور الان در این فروشگاه اکانت تست می‌دهد یا نه؛ روشن بودن اکانت تست، سقف روزانه و سهمیه مشتری‌ها در تنظیمات جدا حساب می‌شوند. سروری که اکانت تستش روشن است ولی الان نمی‌دهد، غیرفعال است، لینک اشتراک نمی‌دهد یا ظرفیتش پر است؛ در فروشگاه نماینده شاید حجم نماینده هم کافی نباشد.',
  },
  settings: {
    title: 'تنظیمات اکانت تست',
    description: 'روشن یا خاموش بودن اکانت تست، سهمیه هر مشتری و سقف روزانه، و حذف خودکار اکانت‌های تمام‌شده.',
  },
}

/** The test accounts in a paragraph, behind the header's ⓘ on every section. */
const PROGRAM =
  'مشتری در ربات دکمه «اکانت تست» را می‌زند، یکی از سرورهایی را که اکانت تست می‌دهند انتخاب می‌کند و همان لحظه یک اکانت رایگان با حجم و مدتی که برای آن سرور تعیین شده می‌گیرد. هر مشتری به اندازه سهمیه‌اش اکانت تست می‌گیرد. اکانت‌های تمام‌شده بعد از مدتی خودشان حذف می‌شوند، ولی در سهمیه مشتری حساب می‌مانند. در فروشگاه نماینده، حجم هر اکانت تست از حجم نماینده کم می‌شود.'

interface TestsPageProps {
  /** A server's own test card, in a panel that has server pages (the owner's): the servers section links each server there. */
  serverPage?: (id: number) => string
}

/** «اکانت تست» (both panels), in three sections: the tests customers took, every server's test terms, and the rules. */
export function TestsPage({ serverPage }: TestsPageProps) {
  const data = useQuery(botSettingsQuery).data?.settings
  const servers = useQuery(testServersQuery).data

  return (
    <SectionedPage
      sections={TEST_SECTIONS}
      header={(current) => ({ ...HEADERS[current.value], info: PROGRAM })}
      above={(current) => (
        <>
          {data && !data.test_enabled && current.value !== 'settings' && (
            <ProgramOff to="/tests/settings" action="روشن کردن در تنظیمات اکانت تست">
              اکانت تست خاموش است: دکمه‌اش در منوی ربات نیست و کسی اکانت تست تازه نمی‌گیرد.
            </ProgramOff>
          )}
          {data?.test_enabled && servers?.every((server) => !server.available) && current.value !== 'servers' && (
            <ProgramOff to="/tests/servers" action="دیدن سرورها">
              الان هیچ سروری اکانت تست نمی‌دهد، پس مشتری‌ها اکانت تست نمی‌گیرند.
            </ProgramOff>
          )}
          {data?.test_enabled && <MenuButtonNotice action="test" label="اکانت تست" screen="گرفتن اکانت تست در ربات" />}
        </>
      )}
    >
      {{
        tests: <TestsList />,
        servers: <TestServersList serverPage={serverPage} />,
        settings: <WithBotSettings what="تنظیمات اکانت تست">{(settings) => <TestSettingsCard settings={settings} />}</WithBotSettings>,
      }}
    </SectionedPage>
  )
}
