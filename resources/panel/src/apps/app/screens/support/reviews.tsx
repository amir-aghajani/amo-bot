import { useState } from 'react'
import { useInfiniteQuery, useMutation } from '@tanstack/react-query'
import { MapPin, MessageSquareOff, MessagesSquare } from 'lucide-react'
import { keys, queryClient } from '@/apps/app/lib/query'
import { useAccount, useShop } from '@/apps/app/lib/session'
import { fieldProblem, problemOf, store } from '@/apps/app/lib/store'
import { StarPicker, Stars } from '@/apps/app/screens/support/parts/stars'
import { backTo } from '@/apps/app/screens/support/parts/words'
import { result } from '@/apps/app/telegram/haptics'
import { Button, MainAction } from '@/apps/app/ui/button'
import { Field, TextArea, TextInput } from '@/apps/app/ui/fields'
import { Screen } from '@/apps/app/ui/screen'
import { Sheet } from '@/apps/app/ui/sheet'
import { Empty, ErrorView, Notice, Skeleton } from '@/apps/app/ui/states'
import { ApiError } from '@/lib/api'
import type { StoreAccountResponse, StoreReview, StoreReviewsResponse } from '@/lib/api-types'
import { formatDate, formatNumber, initials } from '@/lib/format'

/*
 * «نظرات»: what the shop's customers say — the approved reviews, newest first (`GET /reviews`, a page at a time), under
 * their average and count — and «ثبت نظر» (`POST /reviews`): stars, the name it is signed with (the customer's own to
 * start with), the words and, if they like, where they connect from. Signed in, no captcha is asked. It shows once
 * support approves it, which the customer is told. While the shop shows no reviews here (`GET /`'s programs, or the
 * reviews answering 404), the screen says reviews are off.
 */

/** The shop's bounds (Reviews\Services\Reviews): a name of 2 to 64 characters, words of 10 to 600, a context of 100 at most. */
const NAME_MOST = 64
const BODY_LEAST = 10
const BODY_MOST = 600
const CONTEXT_MOST = 100

/** The fields the shop refuses by name; any other refusal is said apart. */
const FIELDS = ['name', 'rating', 'body', 'context'] as const

/** An average of stars as it reads: one decimal, «۴٫۶». */
const averageFormat = new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 1 })

/** The reviews' pages, under keys.reviews. */
const reviewPages = [...keys.reviews, 'pages'] as const

/** The tints a writer's initials are drawn on, one picked by their name. */
const TINTS = ['#ff6b6b', '#f59f00', '#37b24d', '#1c7ed6', '#7048e8', '#e64980', '#0ca678']

function tintOf(name: string): string {
  let sum = 0
  for (const character of name) sum += character.codePointAt(0) ?? 0

  return TINTS[sum % TINTS.length] ?? '#1c7ed6'
}

/** Reviews are off in this shop: the API's 404. */
const reviewsOff = (error: unknown) => error instanceof ApiError && error.status === 404

export default function ReviewsScreen() {
  const [writing, setWriting] = useState(false)
  const [written, setWritten] = useState(false)
  const { data: shop } = useShop()
  // The shop says whether this door shows reviews; one switched off since answers 404 all the same.
  const shown = shop?.shop.programs.reviews ?? true
  const list = useInfiniteQuery({
    queryKey: reviewPages,
    queryFn: ({ pageParam }) => store.get<StoreReviewsResponse>('/reviews', new URLSearchParams({ page: String(pageParam) })),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.page < last.meta.last_page ? last.meta.page + 1 : undefined),
    enabled: shown,
  })
  const reviews = list.data?.pages.flatMap((page) => page.reviews) ?? []
  const summary = list.data?.pages[0]?.meta.summary
  const off = !shown || (list.isError && reviewsOff(list.error))

  return (
    <Screen back={backTo('/')} title="نظرات مشتریان" bottom={off ? 'none' : 'action'}>
      {written && (
        <div role="status">
          <Notice tone="success">نظر شما ثبت شد و پس از تایید پشتیبانی نمایش داده می‌شود.</Notice>
        </div>
      )}

      {off ? (
        <Empty icon={MessageSquareOff} title="نظرات فعال نیست" text="این فروشگاه فعلا نظر مشتریان را نشان نمی‌دهد و نظر تازه‌ای نمی‌گیرد." />
      ) : list.data === undefined ? (
        list.isError ? (
          <ErrorView error={list.error} retry={() => void list.refetch()} />
        ) : (
          <div className="flex flex-col gap-3" role="status" aria-label="در حال بارگذاری">
            <Skeleton className="h-28" />
            <Skeleton className="h-32" />
            <Skeleton className="h-32" />
          </div>
        )
      ) : (
        <>
          {summary !== undefined && summary.average !== null && (
            <div className="card animate-rise flex items-center gap-4 p-4">
              <span className="text-display font-extrabold">{averageFormat.format(summary.average)}</span>
              <span className="flex flex-col gap-1">
                <Stars value={summary.average} size="md" />
                <span className="text-hint text-footnote">{`از ${formatNumber(summary.count)} نظر`}</span>
              </span>
            </div>
          )}
          {reviews.length === 0 ? (
            <Empty icon={MessagesSquare} title="هنوز نظری نیست" text="اولین نفری باشید که از تجربه‌اش می‌گوید." />
          ) : (
            <div className="flex flex-col gap-3">
              {reviews.map((review) => (
                <ReviewCard key={review.id} review={review} />
              ))}
            </div>
          )}
          {list.isFetchNextPageError && <Notice tone="danger">{problemOf(list.error)}</Notice>}
          {list.hasNextPage && (
            <Button variant="secondary" block busy={list.isFetchingNextPage} onClick={() => void list.fetchNextPage()}>
              نظرات بیشتر
            </Button>
          )}
        </>
      )}

      <MainAction text={off || list.isPending ? null : 'ثبت نظر'} onClick={() => setWriting(true)} />
      <Sheet open={writing} onClose={() => setWriting(false)} title="ثبت نظر">
        <ReviewForm
          onWritten={() => {
            setWriting(false)
            setWritten(true)
          }}
        />
      </Sheet>
    </Screen>
  )
}

/** A review: who signed it, its stars and day, its words, and where they connect from. */
function ReviewCard({ review }: { review: StoreReview }) {
  return (
    <article className="card animate-rise flex flex-col gap-3 p-4">
      <header className="flex items-center gap-3">
        {/* Initials, not an icon: a 40px circle in the writer's tint, their letters on its centre line. */}
        <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full text-body leading-none font-bold text-white" style={{ background: tintOf(review.name) }} aria-hidden>
          {initials(review.name) || '؟'}
        </span>
        <span className="flex min-w-0 flex-1 flex-col">
          <span className="truncate text-body font-semibold">{review.name}</span>
          <span className="text-hint text-footnote">{formatDate(review.created_at, { dateStyle: 'medium' })}</span>
        </span>
        <Stars value={review.rating} />
      </header>
      <p className="text-body break-words whitespace-pre-line [unicode-bidi:plaintext]">{review.body}</p>
      {review.context !== null && review.context !== '' && (
        <span className="text-hint flex items-center gap-1.5 text-footnote">
          <MapPin aria-hidden className="size-3.5 shrink-0" />
          <span className="min-w-0 truncate">{review.context}</span>
        </span>
      )}
    </article>
  )
}

/** The name a review starts signed with: the customer's own. */
function nameOf(account: StoreAccountResponse | undefined): string {
  if (account === undefined) return ''

  return [account.customer.first_name, account.customer.last_name].filter(Boolean).join(' ').slice(0, NAME_MOST)
}

function ReviewForm({ onWritten }: { onWritten: () => void }) {
  const { data: account } = useAccount()
  const [rating, setRating] = useState(0)
  const [name, setName] = useState(() => nameOf(account))
  const [body, setBody] = useState('')
  const [context, setContext] = useState('')
  const write = useMutation({
    mutationFn: () => {
      const where = context.trim()
      const review = { name: name.trim(), rating, body: body.trim() }

      return store.post('/reviews', where === '' ? review : { ...review, context: where })
    },
    meta: { quiet: true },
    onSuccess: () => {
      result('success')
      void queryClient.invalidateQueries({ queryKey: keys.reviews })
      onWritten()
    },
    onError: () => result('error'),
  })
  const problem = (field: (typeof FIELDS)[number]) => fieldProblem(write.error, field)
  // A refusal is of what was sent: anything changed, it says nothing of it any more.
  const edit = <T,>(set: (value: T) => void, value: T) => {
    write.reset()
    set(value)
  }
  const general = write.error !== null && FIELDS.every((field) => problem(field) === undefined) ? problemOf(write.error) : null
  const length = [...body.trim()].length
  const ready = rating > 0 && [...name.trim()].length >= 2 && length >= BODY_LEAST

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col items-center gap-2">
        <StarPicker value={rating} onChange={(value) => edit(setRating, value)} />
        {problem('rating') !== undefined ? (
          <p className="text-caption text-danger" role="alert">
            {problem('rating')}
          </p>
        ) : (
          <p className="text-hint text-caption">{rating === 0 ? 'چند ستاره می‌دهید؟' : `${formatNumber(rating)} از ۵ ستاره`}</p>
        )}
      </div>
      <Field label="نام" hint="با این نام نمایش داده می‌شود" error={problem('name')}>
        {(id) => <TextInput id={id} value={name} maxLength={NAME_MOST} onChange={(event) => edit(setName, event.target.value)} invalid={problem('name') !== undefined} />}
      </Field>
      <Field label="نظر شما" hint={`${formatNumber(length)} از ${formatNumber(BODY_MOST)} کاراکتر؛ حداقل ${formatNumber(BODY_LEAST)}`} error={problem('body')}>
        {(id) => (
          <TextArea
            id={id}
            value={body}
            maxLength={BODY_MOST}
            onChange={(event) => edit(setBody, event.target.value)}
            placeholder="از کیفیت اتصال، سرعت و پشتیبانی بگویید"
            invalid={problem('body') !== undefined}
          />
        )}
      </Field>
      <Field label="از کجا وصل می‌شوید؟ (اختیاری)" hint="مثلا اپراتور، دستگاه و برنامه" error={problem('context')}>
        {(id) => (
          <TextInput
            id={id}
            value={context}
            maxLength={CONTEXT_MOST}
            onChange={(event) => edit(setContext, event.target.value)}
            placeholder="ایرانسل · اندروید · Happ"
            invalid={problem('context') !== undefined}
          />
        )}
      </Field>
      {general !== null && (
        <div role="alert">
          <Notice tone="danger">{general}</Notice>
        </div>
      )}
      <Button block busy={write.isPending} disabled={!ready} onClick={() => write.mutate()}>
        ثبت نظر
      </Button>
    </div>
  )
}
