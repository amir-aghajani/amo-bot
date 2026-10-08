import { useId, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Check, Search, Star, Trash2, X } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmModal } from '@/components/confirm-modal'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowMenu } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { Reviewer } from '@/components/reviewer'
import { SearchBox } from '@/components/search-box'
import { StatusBadge, statusTabs } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserLabel, userPage } from '@/components/user-identity'
import { api, stateChanged } from '@/lib/api'
import type { ReviewRow, ReviewsResponse, ReviewStatus } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { messageOf } from '@/lib/failure'
import { formatDate, formatNumber, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { REVIEW_STATUS } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'
import { cn } from '@/lib/utils'

/** The tabs after «همه»: the reviews waiting on support first — the queue —, then the ones the website shows, then the rejected. */
const QUEUE: ReviewStatus[] = ['pending', 'approved', 'rejected']

/** What a decision about a review changes beside the list: the queue's counts on the menu and the dashboard. */
const CHANGES = [queryKeys.queues, queryKeys.dashboards]

/** What support decides about a review from its menu, and what the toast says of it. */
const DECIDED: Record<keyof ReviewRow['actions'], string> = {
  approve: 'نظر تایید شد؛ در وب‌سایت نمایش داده می‌شود',
  reject: 'نظر رد شد؛ در وب‌سایت نمایش داده نمی‌شود',
}

/**
 * The customers' reviews of the shop, written on its website (`/reviews`, both panels): the newest first, the queue
 * waiting on support up front and counted; each one's name, stars, words and where they use the service from, who wrote
 * it — a customer of the shop, or a guest —; approved, the website shows it; rejected — or hidden again later — it does
 * not; deleted (asked first), it is gone.
 */
export function ReviewsPage() {
  const tabs = useId()
  // The dashboard's attention card links here with ?status=pending; a report of the group names a review's number (#12).
  const list = usePagedList({
    queryKey: queryKeys.reviews,
    read: (query) => api.get<ReviewsResponse>('/reviews', query),
    list: 'reviews',
    statuses: ['', ...QUEUE],
    address: '/reviews',
  })
  const [deleting, setDeleting] = useState<ReviewRow | null>(null)

  // Refused because another decided it first (a 422 on its state): the toast says so, and the list is read again.
  const decide = useMutation({
    mutationFn: ({ review, action }: { review: ReviewRow; action: keyof ReviewRow['actions'] }) => api.post(`/reviews/${review.id}/${action}`),
    meta: { invalidates: CHANGES },
    onSuccess: ({ review }, { action }) => {
      list.replace(review)
      toast.success(DECIDED[action])
    },
    onError: (error) => {
      if (stateChanged(error)) list.refetch()
    },
  })

  // The dialog that asked says why it was refused.
  const remove = useMutation({
    mutationFn: (review: ReviewRow) => api.delete(`/reviews/${review.id}`),
    meta: { quiet: true, invalidates: CHANGES },
    onSuccess: (_, review) => {
      list.remove(review.id)
      setDeleting(null)
      toast.success('نظر حذف شد')
    },
  })

  return (
    <Page>
      <PageHeader title="نظرات" description="نظرهایی که مشتری‌ها در وب‌سایت فروشگاه می‌نویسند. نظری که تایید کنید در وب‌سایت نمایش داده می‌شود؛ نظری که در انتظار بررسی است یا رد شده دیده نمی‌شود." />

      <PageTabs id={tabs} value={list.filter} tabs={statusTabs(REVIEW_STATUS, QUEUE, { pending: list.meta?.pending })} onChange={list.setFilter} aria-label="وضعیت نظر" />

      <div {...tabPanel(tabs, list.filter)} className="grid gap-4">
        <SearchBox value={list.typed} onChange={list.setTyped} placeholder="شماره نظر، نام، متن یا مشتری" aria-label="جستجوی نظر" />

        <ListView
          list={list}
          noun="نظرات"
          unit="نظر"
          skeletonRows={6}
          empty={
            <EmptyState framed icon={Star} title="هنوز نظری نوشته نشده است" description="نظرهایی که مشتری‌ها در وب‌سایت فروشگاه می‌نویسند این‌جا می‌آید؛ تا تایید نکنید در وب‌سایت دیده نمی‌شوند." />
          }
          noMatch={
            <EmptyState
              framed
              icon={Search}
              title="نظری پیدا نشد"
              description={list.narrowed ? 'با این جستجو چیزی نیست.' : list.filter === 'pending' ? 'فعلا نظری در انتظار بررسی نیست.' : 'نظری در این وضعیت نیست.'}
            />
          }
        >
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>شماره</TableHead>
                <TableHead>نام</TableHead>
                <TableHead>امتیاز</TableHead>
                <TableHead>نظر</TableHead>
                <TableHead>وضعیت</TableHead>
                <TableHead className="hidden sm:table-cell">زمان</TableHead>
                <TableHead className="w-10">
                  <span className="sr-only">عملیات</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.rows.map((review) => (
                <TableRow key={review.id}>
                  <TableCell className="text-muted-foreground tabular">
                    <span dir="ltr">#{review.id}</span>
                  </TableCell>
                  <TableCell>
                    <Writer review={review} />
                  </TableCell>
                  <TableCell>
                    <Stars rating={review.rating} />
                  </TableCell>
                  <TableCell className="max-w-md">
                    {/* The writer's words whole — what support decides on —, wrapping in their own measure. */}
                    <p dir="auto" className="min-w-56 wrap-break-word whitespace-pre-line">
                      {review.body}
                    </p>
                    {review.context && (
                      <p dir="auto" className="mt-1 text-footnote text-muted-foreground">
                        {review.context}
                      </p>
                    )}
                  </TableCell>
                  <TableCell>
                    {/* The spaces between the lines keep them words apart for assistive tech, not one. */}
                    <div className="grid justify-items-start gap-1">
                      <StatusBadge status={REVIEW_STATUS[review.status]} />{' '}
                      {review.decided_at && (
                        <span className="text-footnote text-muted-foreground">
                          {review.reviewer && (
                            <>
                              <Reviewer name={review.reviewer} />
                              {' · '}
                            </>
                          )}
                          {timeAgo(review.decided_at)}
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="hidden text-muted-foreground sm:table-cell">
                    <time dateTime={review.created_at} title={formatDate(review.created_at)}>
                      {timeAgo(review.created_at)}
                    </time>
                  </TableCell>
                  <TableCell>
                    <RowMenu label={`نظر ${idLabel(review.id)}`}>
                      {review.actions.approve && (
                        <DropdownMenuItem disabled={decide.isPending} onSelect={() => decide.mutate({ review, action: 'approve' })}>
                          <Check aria-hidden />
                          تایید
                        </DropdownMenuItem>
                      )}
                      {review.actions.reject && (
                        <DropdownMenuItem disabled={decide.isPending} onSelect={() => decide.mutate({ review, action: 'reject' })}>
                          <X aria-hidden />
                          رد
                        </DropdownMenuItem>
                      )}
                      <DropdownMenuSeparator />
                      <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(review)}>
                        <Trash2 aria-hidden />
                        حذف
                      </DropdownMenuItem>
                    </RowMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </ListView>
      </div>

      <ConfirmModal
        open={deleting !== null}
        onClose={() => {
          setDeleting(null)
          remove.reset()
        }}
        title="حذف نظر"
        description={deleting ? `نظر ${idLabel(deleting.id)}` : undefined}
        confirmLabel="حذف"
        destructive
        pending={remove.isPending}
        error={remove.error ? messageOf(remove.error) : null}
        onConfirm={() => deleting && remove.mutate(deleting)}
      >
        این نظر برای همیشه پاک می‌شود و برگشت ندارد. برای این‌که فقط در وب‌سایت دیده نشود، کافی است آن را رد کنید.
      </ConfirmModal>
    </Page>
  )
}

/** The name the review is signed with, and who wrote it: a customer of the shop — their page —, or a guest. */
function Writer({ review }: { review: ReviewRow }) {
  return (
    <div className="grid min-w-28 gap-0.5">
      <bdi className="font-medium">{review.name}</bdi>
      <span className="text-footnote text-muted-foreground">
        {review.customer ? (
          <TextLink to={userPage(review.customer.id)}>
            <UserLabel user={review.customer} />
          </TextLink>
        ) : (
          'مهمان'
        )}
      </span>
    </div>
  )
}

/** The review's stars — as many filled as its rating —, read as «۴ از ۵». */
function Stars({ rating }: { rating: number }) {
  return (
    <span role="img" aria-label={`${formatNumber(rating)} از ${formatNumber(5)}`} className="inline-flex items-center gap-0.5 whitespace-nowrap">
      {[1, 2, 3, 4, 5].map((star) => (
        <Star key={star} aria-hidden className={cn('size-3.5', star <= rating ? 'fill-current text-warning' : 'text-faint')} />
      ))}
    </span>
  )
}
