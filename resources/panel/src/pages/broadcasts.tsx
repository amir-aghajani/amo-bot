import type { ReactNode } from 'react'
import { Info } from 'lucide-react'
import { BroadcastsList } from '@/components/broadcasts/broadcasts-list'
import { Callout } from '@/components/callout'
import { SectionedPage } from '@/components/sectioned-page'
import { BOT_GROUP, BROADCAST_MESSAGES, type NavSection } from '@/components/shell/nav'
import { useMainShop } from '@/lib/auth'

/** The page's own name, the menu's: what a page of the messages alone (an agent's) is called. */
const PAGE_TITLE = BOT_GROUP.items.find((item) => item.to === BROADCAST_MESSAGES.to)?.title ?? BROADCAST_MESSAGES.title

/** A section a panel adds beside the messages — the owner's «هدیه همگانی»: its address, its header's words, what it shows. */
export interface BroadcastsSection {
  section: NavSection
  description: string
  content: ReactNode
}

/**
 * «ارسال همگانی»: the messages the bot's admins send to customers from the bot (/broadcast), live, with their controls —
 * the shop's own bot's —, and the sections a panel adds beside them (`more`), each listed in the sidebar with the
 * messages, whose header then names the section on screen; a page of the messages alone is titled by its own name, as
 * the menu, the topbar and the tab call it. Every section stays mounted, so each keeps its page.
 */
export function BroadcastsPage({ more = [] }: { more?: BroadcastsSection[] }) {
  // The main bot's shop also has its agents and its servers' customers among its audiences; an agent's bot neither.
  const mainShop = useMainShop()
  const messages = {
    title: more.length === 0 ? PAGE_TITLE : BROADCAST_MESSAGES.title,
    description: mainShop
      ? 'پیام به گروهی از مشتری‌ها — همه، خریداران، غیرفعال‌ها، یک گروه، نماینده‌ها یا مشتری‌های یک سرور.'
      : 'پیام به گروهی از مشتری‌های ربات — همه، خریداران، غیرفعال‌ها یا یک گروه.',
  }

  return (
    <SectionedPage
      sections={[BROADCAST_MESSAGES, ...more.map((extra) => extra.section)]}
      header={(current) => {
        const extra = more.find((section) => section.section.value === current.value)
        return extra ? { title: extra.section.title, description: extra.description } : messages
      }}
    >
      {{
        [BROADCAST_MESSAGES.value]: (
          <>
            <Callout tone="info" icon={Info}>
              پیام همگانی را در خود ربات می‌فرستید: یکی از مدیرهای ربات <bdi dir="ltr">/broadcast</bdi> را می‌فرستد، بعد پیام را (هر نوع پیامی، یا پستی از کانال برای فوروارد) و در پیش‌نویس مخاطب، پین
              و دکمه‌ها را انتخاب می‌کند. پیشرفت هر ارسال هم در ربات و هم همین‌جا زنده دیده می‌شود و از هر دو جا می‌شود متوقف، ادامه یا لغوش کرد.
            </Callout>
            <BroadcastsList />
          </>
        ),
        ...Object.fromEntries(more.map((section) => [section.section.value, section.content])),
      }}
    </SectionedPage>
  )
}
