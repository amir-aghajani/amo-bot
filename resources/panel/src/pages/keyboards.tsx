import { useQuery, useQueryClient } from '@tanstack/react-query'
import { fillSamples } from '@/components/bot-texts/telegram-html'
import { ErrorState } from '@/components/error-state'
import { KeyboardEditor } from '@/components/keyboards/keyboard-editor'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { Skeleton } from '@/components/ui/skeleton'
import type { BotTextsResponse, KeyboardLayoutData } from '@/lib/api-types'
import { botTextsQuery, keyboardsQuery } from '@/lib/queries'

/** The bot text the start menu goes out with (BotText::Welcome). */
const WELCOME = 'welcome'

/** The greeting in the admin's wording, its variables filled with their samples — what the preview shows above the menu. */
function welcomeOf(data: BotTextsResponse): string | undefined {
  const text = data.groups.flatMap((group) => group.texts).find((row) => row.key === WELCOME)
  return text && fillSamples(text.value, Object.fromEntries(text.variables.map((variable) => [variable.name, variable.sample])), text.html)
}

/** The bot's keyboards: an editor for each one the API lists (the start menu). */
export function KeyboardsPage() {
  const queryClient = useQueryClient()
  const { data, error, refetch } = useQuery(keyboardsQuery)
  const { data: welcome } = useQuery({ ...botTextsQuery, select: welcomeOf })

  const replace = (keyboard: KeyboardLayoutData) =>
    queryClient.setQueryData(keyboardsQuery.queryKey, (current) => current && { ...current, keyboards: current.keyboards.map((k) => (k.name === keyboard.name ? keyboard : k)) })

  return (
    <Page width="default">
      <PageHeader title="کیبوردها" description="دکمه‌هایی که ربات به مشتری نشان می‌دهد؛ چه دکمه‌ای، با چه متن و آیکونی، در کدام ردیف و به چه رنگی." />

      {/* A later read that fails keeps the editors (and their drafts) on what was read before. */}
      {data ? (
        data.keyboards.map((keyboard) => (
          <KeyboardEditor key={keyboard.name} keyboard={keyboard} actions={data.actions} styles={data.styles} limits={data.limits} welcome={welcome} onSaved={replace} />
        ))
      ) : error ? (
        <ErrorState what="کیبوردها" error={error} onRetry={() => void refetch()} />
      ) : (
        <Skeleton className="h-96 w-full rounded-xl" />
      )}
    </Page>
  )
}
