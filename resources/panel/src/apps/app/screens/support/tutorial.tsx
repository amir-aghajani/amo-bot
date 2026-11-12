import { useQuery } from '@tanstack/react-query'
import { MessageCircleQuestion, Wifi } from 'lucide-react'
import { keys } from '@/apps/app/lib/query'
import { store } from '@/apps/app/lib/store'
import { ConnectionGuides } from '@/apps/app/screens/support/parts/guides'
import { TelegramHtml } from '@/apps/app/screens/support/parts/telegram-html'
import { backTo } from '@/apps/app/screens/support/parts/words'
import { Screen } from '@/apps/app/ui/screen'
import { Row, Section } from '@/apps/app/ui/section'
import { ErrorView, Skeleton } from '@/apps/app/ui/states'
import type { StoreTutorialResponse } from '@/lib/api-types'

/*
 * «آموزش»: the shop's own tutorial — the very text the bot shows under its «آموزش» button (`GET /tutorial`, Telegram's
 * HTML, rendered safely by parts/telegram-html) —, then short guides to connecting with the common apps, and the way to
 * support for what neither answers.
 */

export default function TutorialScreen() {
  const tutorial = useQuery({ queryKey: keys.tutorial, queryFn: () => store.get<StoreTutorialResponse>('/tutorial'), staleTime: 5 * 60_000 })
  const text = tutorial.data?.text.trim() ?? ''

  return (
    <Screen back={backTo('/')} title="آموزش">
      {tutorial.isPending ? (
        <Skeleton className="h-48" />
      ) : tutorial.isError ? (
        <ErrorView error={tutorial.error} retry={() => void tutorial.refetch()} />
      ) : (
        text !== '' && (
          <Section title="راهنمای فروشگاه">
            {/* Long reading: 28px lines. */}
            <TelegramHtml html={text} className="p-4 text-body leading-7" />
          </Section>
        )
      )}

      <Section title="اتصال با برنامه‌ها" footer="لینک اشتراک هر سرویس در صفحه همان سرویس است؛ «اتصال سریع» آن را مستقیم به برنامه می‌دهد." bare>
        <ConnectionGuides />
      </Section>

      <Section>
        <Row icon={Wifi} title="سرویس‌های من" subtitle="لینک اشتراک و اتصال سریع" to="/services" />
        <Row icon={MessageCircleQuestion} tint="#ff9500" title="جواب سوالتان را پیدا نکردید؟" subtitle="به پشتیبانی پیام دهید" to="/support/new" />
      </Section>
    </Screen>
  )
}
