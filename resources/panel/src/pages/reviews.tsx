import { useId, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Check, Search, Star, Trash2, X } from 'lucide-react'
import { toast } from 'sonner'
import { EmptyState } from '@/components/empty-state'
import { ListView, PhoneFacts, RowMenu } from '@/components/list-view'
import { Page } from '@/components/page'
import { PageHeader } from '@/components/page-header'
import { PageTabs, tabPanel } from '@/components/page-tabs'
import { Rating } from '@/components/rating'
import { Reviewer } from '@/components/reviewer'
import { SearchBox } from '@/components/search-box'
import { RemoveConfirm } from '@/components/sortable-list'
import { StatusBadge, statusTabs } from '@/components/status-badge'
import { TextLink } from '@/components/text-link'
import { TimeAgo } from '@/components/time-ago'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserLabel, userPage } from '@/components/user-identity'
import { api, stateChanged } from '@/lib/api'
import type { ReviewRow, ReviewsResponse, ReviewStatus } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { REVIEW_STATUS } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'

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
 * «نظرات» (both panels): reviews written on the website, newest first, the pending ones as the first tab. Approved ones
 * show on the website; rejecting hides one; deleting (asked first) removes it.
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

  // Quiet: RemoveConfirm says a refusal in its dialog.
  const remove = useMutation({
    mutationFn: (review: ReviewRow) => api.delete(`/reviews/${review.id}`),
    meta: { quiet: true, invalidates: CHANGES },
    onSuccess: (_, review) => list.remove(review.id),
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
                <TableHead className="hidden md:table-cell">شماره</TableHead>
                <TableHead className="hidden md:table-cell">نام</TableHead>
                <TableHead className="hidden md:table-cell">امتیاز</TableHead>
                <TableHead>نظر</TableHead>
                <TableHead className="hidden md:table-cell">وضعیت</TableHead>
                <TableHead className="hidden sm:table-cell">زمان</TableHead>
                <TableHead className="w-10">
                  <span className="sr-only">عملیات</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.rows.map((review) => (
                <TableRow key={review.id}>
                  <TableCell className="hidden text-muted-foreground tabular md:table-cell">
                    <span dir="ltr">#{review.id}</span>
                  </TableCell>
                  <TableCell className="hidden md:table-cell">
                    <Writer review={review} />
                  </TableCell>
                  <TableCell className="hidden md:table-cell">
                    <Rating rating={review.rating} />
                  </TableCell>
                  <TableCell className="max-w-md">
                    {/* On a phone the review is the row: who wrote it, its stars and its state come with its words. */}
                    <PhoneFacts className="pt-0 pb-1.5">
                      <Writer review={review} />
                      <Rating rating={review.rating} />
                    </PhoneFacts>
                    {/* The writer's words whole — what support decides on —, wrapping in their own measure. */}
                    <p dir="auto" className="min-w-56 wrap-break-word whitespace-pre-line">
                      {review.body}
                    </p>
                    {review.context && (
                      <p dir="auto" className="mt-1 text-footnote text-muted-foreground">
                        {review.context}
                      </p>
                    )}
                    <PhoneFacts>
                      <span dir="ltr">#{review.id}</span>
                      <Decision review={review} />
                    </PhoneFacts>
                  </TableCell>
                  <TableCell className="hidden md:table-cell">
                    <Decision review={review} />
                  </TableCell>
                  <TableCell className="hidden text-muted-foreground sm:table-cell">
                    <TimeAgo at={review.created_at} />
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

      <RemoveConfirm
        row={deleting}
        remove={remove}
        title="حذف نظر"
        description={(review) => `نظر ${idLabel(review.id)}`}
        details={() => 'این نظر برای همیشه پاک می‌شود و برگشت ندارد. برای این‌که فقط در وب‌سایت دیده نشود، کافی است آن را رد کنید.'}
        removed="نظر حذف شد"
        onClose={() => setDeleting(null)}
      />
    </Page>
  )
}

/** The review's state, and who decided it when. */
function Decision({ review }: { review: ReviewRow }) {
  return (
    // The spaces between the lines keep them words apart for assistive tech, not one.
    <div className="grid justify-items-start gap-1">
      <StatusBadge status={REVIEW_STATUS[review.status]} />{' '}
      {review.decided_at && (
        <span className="text-footnote text-muted-foreground">
          {review.reviewer && (
            <>
              <Reviewer name={review.reviewer} person={review.reviewer_name} />
              {' · '}
            </>
          )}
          {timeAgo(review.decided_at)}
        </span>
      )}
    </div>
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
