import { useMemo } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { emojiLookup, mapWording } from '@/components/bot-texts/emoji-map'
import { fillSamples } from '@/components/bot-texts/telegram-html'
import { ErrorState } from '@/components/error-state'
import { KeyboardEditor } from '@/components/keyboards/keyboard-editor'
import { Page, useReadFailure } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { Skeleton } from '@/components/ui/skeleton'
import type { BotTextRow, BotTextsResponse, KeyboardLayoutData } from '@/lib/api-types'
import { botTextsQuery, keyboardsQuery, premiumTextsQuery } from '@/lib/queries'
import { queryKeys } from '@/lib/query-keys'

/** The bot text the start menu goes out with (BotText::Welcome). */
const WELCOME = 'welcome'

/** The greeting the start menu goes out with. */
const welcomeOf = (data: BotTextsResponse): BotTextRow | undefined => data.groups.flatMap((group) => group.texts).find((row) => row.key === WELCOME)

/** The bot's keyboards: an editor for each one the API lists (the start menu). */
export function KeyboardsPage() {
  const queryClient = useQueryClient()
  const read = useQuery(keyboardsQuery)
  const { data, refetch } = read
  const failure = useReadFailure(read)
  const { data: text } = useQuery({ ...botTextsQuery, select: welcomeOf })
  // The shop's premium emoji, as the bot draws them: the icons they give menu buttons without their own, and those of
  // the greeting's own words.
  const { data: icons } = useQuery({ ...premiumTextsQuery, select: emojiLookup })
  // The greeting in the admin's wording, its variables filled with their samples — what the preview shows above the menu.
  const welcome = useMemo(
    () => text && fillSamples(mapWording(text.value, text.kind, icons ?? new Map()), Object.fromEntries(text.variables.map((variable) => [variable.name, variable])), text.html),
    [text, icons],
  )

  const replace = (keyboard: KeyboardLayoutData) => {
    queryClient.setQueryData(keyboardsQuery.queryKey, (current) => current && { ...current, keyboards: current.keyboards.map((k) => (k.name === keyboard.name ? keyboard : k)) })
    // The start menu's buttons count in «ایموجی پرمیوم متن‌ها» (where each emoji is) while it is inline buttons.
    void queryClient.invalidateQueries({ queryKey: queryKeys.premiumTexts })
  }

  return (
    <Page width="default">
      <PageHeader title="کیبوردها" description="دکمه‌هایی که ربات به مشتری نشان می‌دهد؛ چه دکمه‌ای، با چه متن و آیکونی، در کدام ردیف و به چه رنگی." />

      {/* A later read that fails keeps the editors (and their drafts) on what was read before. */}
      {data ? (
        data.keyboards.map((keyboard) => (
          <KeyboardEditor key={keyboard.name} keyboard={keyboard} actions={data.actions} styles={data.styles} limits={data.limits} welcome={welcome} icons={icons} onSaved={replace} />
        ))
      ) : failure.error ? (
        <ErrorState what="کیبوردها" error={failure.error} onRetry={() => void refetch()} retrying={failure.retrying} />
      ) : (
        <Skeleton className="h-96 w-full rounded-xl" />
      )}
    </Page>
  )
}
