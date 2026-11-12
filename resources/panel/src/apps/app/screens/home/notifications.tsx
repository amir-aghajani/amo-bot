import type { CSSProperties } from 'react'
import { useInfiniteQuery, useMutation, type InfiniteData } from '@tanstack/react-query'
import { BellOff, CheckCheck } from 'lucide-react'
import { useNavigate } from 'react-router'
import { keys, queryClient } from '@/apps/app/lib/query'
import { store } from '@/apps/app/lib/store'
import { noticeLines, noticeLink, noticeLook } from '@/apps/app/screens/home/parts/notice'
import { tap } from '@/apps/app/telegram/haptics'
import { inPreview } from '@/apps/app/telegram/webapp'
import { Button } from '@/apps/app/ui/button'
import { Screen } from '@/apps/app/ui/screen'
import { Empty, ErrorView, LoadingBlocks } from '@/apps/app/ui/states'
import { IconTile } from '@/apps/app/ui/visuals'
import type { StoreAccountResponse, StoreNotification, StoreNotificationsResponse } from '@/lib/api-types'
import { formatDate, timeAgo } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * «اعلان‌ها»: everything the shop told the customer, newest first and a page at a time, the unread ones marked. A tap
 * marks a notice read (not in a panel's preview) and opens what it is about; «خواندن همه» marks them all. The bell's
 * count follows each answer.
 */

const FEED = [...keys.notifications, 'pages'] as const

type Feed = InfiniteData<StoreNotificationsResponse, number>

/** The feed as the shop now has it: those marked (`ids`, or every one for null) read, `unread` left. */
function markedRead(ids: number[] | null, unread: number): void {
  queryClient.setQueryData<Feed>(FEED, (feed) =>
    feed === undefined
      ? feed
      : {
          ...feed,
          pages: feed.pages.map((page) => ({
            notifications: page.notifications.map((notice) => (ids === null || ids.includes(notice.id) ? { ...notice, read: true } : notice)),
            meta: { ...page.meta, unread },
          })),
        },
  )
  queryClient.setQueryData<StoreAccountResponse>(keys.me, (account) => (account === undefined ? account : { ...account, unread_notifications: unread }))
}

/** The hairline under a notice starts where its words do: past its 40px icon and the 12px after it. */
const ROW_INSET = { '--row-inset': '4.25rem' } as CSSProperties

function NoticeRow({ notice, onOpen }: { notice: StoreNotification; onOpen: () => void }) {
  const { icon, tint } = noticeLook(notice.type)
  const { head, rest } = noticeLines(notice.text)

  return (
    <li className="list-row" style={ROW_INSET}>
      <button
        type="button"
        onClick={onOpen}
        className={cn('flex w-full items-start gap-3 px-4 py-3 text-start active:bg-fill', !notice.read && 'bg-[color-mix(in_srgb,var(--app-accent)_6%,transparent)]')}
      >
        <IconTile icon={icon} size="lg" color={tint} look="soft" round />
        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="flex items-start gap-2">
            <span className={cn('min-w-0 flex-1 text-body', notice.read ? 'font-medium' : 'font-bold')}>{head}</span>
            <time dateTime={notice.created_at ?? undefined} title={formatDate(notice.created_at)} className="text-hint shrink-0 text-caption leading-6">
              {timeAgo(notice.created_at)}
            </time>
          </span>
          {rest !== '' && <span className="text-hint text-footnote break-words whitespace-pre-line">{rest}</span>}
        </span>
        {!notice.read && <span role="img" aria-label="خوانده‌نشده" className="bg-accent mt-2 size-2 shrink-0 rounded-full" />}
      </button>
    </li>
  )
}

export default function NotificationsScreen() {
  const navigate = useNavigate()
  const feed = useInfiniteQuery({
    queryKey: FEED,
    queryFn: ({ pageParam }) => store.get<StoreNotificationsResponse>('/notifications', pageParam === 1 ? undefined : new URLSearchParams({ page: String(pageParam) })),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
  })

  // One notice read on its tap: quiet — its link opens all the same.
  const readOne = useMutation({
    mutationFn: (id: number) => store.post('/notifications/read', { ids: [id] }),
    meta: { quiet: true },
    onSuccess: ({ unread }, id) => markedRead([id], unread),
  })
  const readAll = useMutation({
    mutationFn: () => store.post('/notifications/read', {}),
    onSuccess: ({ unread }) => markedRead(null, unread),
  })

  const pages = feed.data?.pages
  const unread = pages?.[0]?.meta.unread ?? 0
  const notices = pages?.flatMap((page) => page.notifications) ?? []

  const open = (notice: StoreNotification) => {
    tap()
    // A panel's preview opens it without marking it: the mark is the customer's.
    if (!notice.read && !inPreview()) readOne.mutate(notice.id)
    const link = noticeLink(notice)
    if (link !== null) navigate(link)
  }

  return (
    <Screen
      back
      title="اعلان‌ها"
      actions={
        unread > 0 && (
          <Button variant="plain" size="sm" busy={readAll.isPending} icon={<CheckCheck />} onClick={() => readAll.mutate()}>
            خواندن همه
          </Button>
        )
      }
    >
      {feed.isError && pages === undefined ? (
        <ErrorView error={feed.error} retry={() => void feed.refetch()} />
      ) : pages === undefined ? (
        <LoadingBlocks count={5} />
      ) : notices.length === 0 ? (
        <Empty icon={BellOff} title="اعلانی ندارید" text="خبرهای فروشگاه درباره سفارش‌ها، سرویس‌ها و کیف پول شما اینجا می‌آید." />
      ) : (
        <>
          <ul className="card animate-rise overflow-hidden">
            {notices.map((notice) => (
              <NoticeRow key={notice.id} notice={notice} onOpen={() => open(notice)} />
            ))}
          </ul>
          {feed.hasNextPage && (
            <Button variant="secondary" block busy={feed.isFetchingNextPage} onClick={() => void feed.fetchNextPage()}>
              اعلان‌های بیشتر
            </Button>
          )}
          <p className="text-hint px-4 text-center text-caption">اعلان‌های قدیمی پس از مدتی پاک می‌شوند.</p>
        </>
      )}
    </Screen>
  )
}
