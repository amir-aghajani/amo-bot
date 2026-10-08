import { memo, useDeferredValue, useId, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MessageSquareText, Pencil, RotateCcw, Search } from 'lucide-react'
import { toast } from 'sonner'
import { KIND_LABELS } from '@/components/bot-texts/kinds'
import { plainText } from '@/components/bot-texts/telegram-html'
import { TextModal } from '@/components/bot-texts/text-modal'
import { WithTokens } from '@/components/bot-texts/tokens'
import { ConfirmModal } from '@/components/confirm-modal'
import { EmptyState } from '@/components/empty-state'
import { ErrorState } from '@/components/error-state'
import { RowMenu, RowTitleButton } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel, type PageTab } from '@/components/page-tabs'
import { SearchBox } from '@/components/search-box'
import { CountBadge } from '@/components/status-badge'
import { Badge } from '@/components/ui/badge'
import { Card, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { DropdownMenuItem } from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import { api } from '@/lib/api'
import type { BotTextRow } from '@/lib/api-types'
import { formatNumber } from '@/lib/format'
import { botTextsQuery } from '@/lib/queries'

type Filter = 'all' | 'customized'

/** Persian as people type it: Arabic ی/ک the same as Persian, the zero-width non-joiner and case ignored. */
function normalize(text: string): string {
  return text.toLowerCase().replace(/‌/g, '').replace(/ي/g, 'ی').replace(/ك/g, 'ک')
}

/**
 * Every text the bot sends a customer, by where it shows, each reworded in a modal with its variables and
 * a Telegram preview. The shop's wording stays the default until the admin changes it. The list is long: its rows stay
 * drawn whatever the search and the tab — those only hide the rest —, and follow the field and the tabs a beat behind,
 * so typing and switching answer at once.
 */
export function BotTextsPage() {
  const queryClient = useQueryClient()
  const { data, error, refetch } = useQuery(botTextsQuery)
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const [editing, setEditing] = useState<BotTextRow | null>(null)
  const [resetting, setResetting] = useState<BotTextRow | null>(null)
  const tabsId = useId()
  const term = normalize(useDeferredValue(search).trim())
  const listed = useDeferredValue(filter)
  // What a search finds each text by — its title, what it is for, its wording, its key —, read once an answer.
  const words = useMemo(
    () => new Map((data?.groups ?? []).flatMap((group) => group.texts).map((text) => [text.key, normalize([text.title, text.description, text.value, text.key].join('\n'))])),
    [data],
  )
  const matches = (text: BotTextRow) => term === '' || (words.get(text.key) ?? '').includes(term)
  const shows = (text: BotTextRow) => (listed === 'all' || text.customized) && matches(text)

  const replace = (text: BotTextRow) =>
    queryClient.setQueryData(
      botTextsQuery.queryKey,
      (current) => current && { groups: current.groups.map((group) => ({ ...group, texts: group.texts.map((row) => (row.key === text.key ? text : row)) })) },
    )

  const reset = useMutation({
    mutationFn: (text: BotTextRow) => api.post(`/bot/texts/${text.key}/reset`),
    onSuccess: (result) => {
      replace(result.text)
      setResetting(null)
      toast.success('متن پیش‌فرض دوباره به کار می‌رود')
    },
  })

  const groups = data?.groups ?? []
  // The tabs count what the search finds, each its own share of it.
  const found = groups.flatMap((group) => group.texts).filter(matches)
  const customized = found.filter((text) => text.customized).length
  const shownAny = found.some((text) => listed === 'all' || text.customized)

  const tabs: PageTab<Filter>[] = [
    {
      value: 'all',
      label: (
        <>
          همه
          <CountBadge count={found.length} />
        </>
      ),
    },
    {
      value: 'customized',
      label: (
        <>
          تغییر یافته
          <CountBadge count={customized} tone={customized > 0 ? 'info' : 'neutral'} />
        </>
      ),
    },
  ]

  return (
    <Page width="default">
      <PageHeader
        title="متن‌های ربات"
        description={
          <>
            هر پیامی که ربات برای مشتری می‌فرستد، با متن خودتان. متغیرهایی مثل{' '}
            <bdi dir="ltr" className="text-footnote">
              %name%
            </bdi>{' '}
            هنگام ارسال با مقدار واقعی پر می‌شوند.
          </>
        }
      />

      <PageTabs id={tabsId} value={filter} tabs={tabs} onChange={setFilter} aria-label="متن‌های نمایش‌داده‌شده" />

      <div {...tabPanel(tabsId, filter)} className="grid gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <SearchBox value={search} onChange={setSearch} placeholder="عنوان یا بخشی از متن" aria-label="جستجوی متن" />
        </div>

        {!data ? (
          error ? (
            <ErrorState what="متن‌های ربات" error={error} onRetry={() => void refetch()} />
          ) : (
            [0, 1].map((i) => <Skeleton key={i} className="h-64 w-full rounded-xl" />)
          )
        ) : (
          <>
            {!shownAny &&
              (listed === 'customized' && term === '' ? (
                <EmptyState framed icon={MessageSquareText} title="هنوز متنی تغییر نکرده است" description="ربات همه متن‌ها را با متن پیش‌فرض فروشگاه می‌فرستد." />
              ) : (
                <EmptyState framed icon={Search} title="متنی پیدا نشد" description="با این جستجو یا فیلتر متنی نیست." />
              ))}
            {groups.map((group) => {
              const shown = group.texts.filter(shows)
              const changed = shown.filter((text) => text.customized).length
              return (
                <Card key={group.key} hidden={shown.length === 0}>
                  <CardHeader className="border-b border-border">
                    <CardHeading>
                      <CardTitle className="text-heading">{group.title}</CardTitle>
                      <CardDescription>
                        {formatNumber(shown.length)} متن{changed > 0 && ` · ${formatNumber(changed)} تغییر یافته`}
                      </CardDescription>
                    </CardHeading>
                  </CardHeader>
                  {/* A rule between two rows on screen, a hidden one in between or not. */}
                  <ul className="[&>li:not([hidden])~li:not([hidden])]:border-t">
                    {group.texts.map((text) => (
                      <TextItem key={text.key} text={text} hidden={!shows(text)} onEdit={setEditing} onReset={setResetting} />
                    ))}
                  </ul>
                </Card>
              )
            })}
          </>
        )}
      </div>

      <TextModal
        text={editing}
        onClose={() => setEditing(null)}
        onSaved={(text) => {
          replace(text)
          setEditing(null)
        }}
      />

      <ConfirmModal
        open={resetting !== null}
        onClose={() => setResetting(null)}
        title="بازگشت به متن پیش‌فرض"
        description={resetting && `«${resetting.title}» دوباره با متن پیش‌فرض فروشگاه فرستاده می‌شود.`}
        confirmLabel="بازگشت به پیش‌فرض"
        pending={reset.isPending}
        onConfirm={() => resetting && reset.mutate(resetting)}
      >
        متن فعلی شما کنار گذاشته می‌شود و برنمی‌گردد.
      </ConfirmModal>
    </Page>
  )
}

interface TextItemProps {
  text: BotTextRow
  /** Out of what the search and the tab show: kept, drawn, hidden. */
  hidden: boolean
  onEdit: (text: BotTextRow) => void
  onReset: (text: BotTextRow) => void
}

/** A text's row — drawn again only when the text or whether it shows changes, not at every key of the search. */
const TextItem = memo(function TextItem({ text, hidden, onEdit, onReset }: TextItemProps) {
  const preview = useMemo(() => plainText(text.value), [text.value])

  return (
    <li hidden={hidden} className="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-fill/60">
      <div className="grid min-w-0 flex-1 gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <RowTitleButton onClick={() => onEdit(text)}>{text.title}</RowTitleButton>
          <Badge variant="outline" className="font-normal">
            {KIND_LABELS[text.kind]}
          </Badge>
          {text.customized && <Badge variant="info">تغییر یافته</Badge>}
        </div>
        <p className="text-footnote text-muted-foreground">
          <WithTokens text={text.description} />
        </p>
        <p className="line-clamp-2 rounded-lg bg-fill px-2.5 py-1.5 text-footnote wrap-anywhere whitespace-pre-line text-foreground/85">
          {preview === '' ? <span className="text-faint">(خالی)</span> : <WithTokens text={preview} />}
        </p>
      </div>
      <RowMenu label={text.title}>
        <DropdownMenuItem onSelect={() => onEdit(text)}>
          <Pencil aria-hidden />
          ویرایش
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={() => onReset(text)} disabled={!text.customized}>
          <RotateCcw aria-hidden />
          بازگشت به متن پیش‌فرض
        </DropdownMenuItem>
      </RowMenu>
    </li>
  )
})
