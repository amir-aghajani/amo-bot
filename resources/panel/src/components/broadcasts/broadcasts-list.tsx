import { useState, type ReactNode } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Ban, BarChart3, Disc, File, Film, Image, Megaphone, MessageSquare, Mic, Music, Pause, Pin, PinOff, Play, Smile, Type, Video, type LucideIcon } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmModal } from '@/components/confirm-modal'
import { EmptyState } from '@/components/empty-state'
import { ListView, RowMenu } from '@/components/list-view'
import { ProgressBar } from '@/components/progress-bar'
import { Reviewer } from '@/components/reviewer'
import { StatusBadge } from '@/components/status-badge'
import { Badge } from '@/components/ui/badge'
import { DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { UserIdentity } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { BroadcastRow, BroadcastsResponse } from '@/lib/api-types'
import { idLabel } from '@/lib/direction'
import { formatDate, formatNumber, timeAgo } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'
import { BROADCAST_STATUS } from '@/lib/statuses'
import { usePagedList } from '@/lib/use-paged-list'

/** What a message is, in the list's words and icon (the bot's own field names). */
const CONTENT: Record<string, { label: string; icon: LucideIcon }> = {
  text: { label: 'متن', icon: Type },
  photo: { label: 'عکس', icon: Image },
  video: { label: 'ویدیو', icon: Video },
  animation: { label: 'گیف', icon: Film },
  document: { label: 'فایل', icon: File },
  audio: { label: 'آهنگ', icon: Music },
  voice: { label: 'پیام صوتی', icon: Mic },
  video_note: { label: 'ویدیو مسیج', icon: Disc },
  sticker: { label: 'استیکر', icon: Smile },
  poll: { label: 'نظرسنجی', icon: BarChart3 },
}
const OTHER = { label: 'پیام', icon: MessageSquare }

type Action = keyof BroadcastRow['actions']

/** What changes the run itself; «لغو پین» starts a run of its own. */
type Control = Exclude<Action, 'unpin'>

/**
 * Every «ارسال همگانی» run, newest first and live (the change feed moves it while it sends): what went, to whom, how far
 * it got, and the controls its state allows — the same as the bot's progress message, which follows. A cancel and a
 * «لغو پین» ask first.
 */
export function BroadcastsList() {
  const list = usePagedList({ queryKey: queryKeys.broadcasts, read: (query) => api.get<BroadcastsResponse>('/broadcasts', query), list: 'broadcasts', address: '/broadcasts' })
  const [confirming, setConfirming] = useState<{ action: 'cancel' | 'unpin'; broadcast: BroadcastRow } | null>(null)

  // Refused (its state moved on — the bot's buttons, the scheduler), the list is read again; the toast says why.
  const refused = () => {
    list.refetch()
    setConfirming(null)
  }

  // A pause, a resume, a cancel: the run as the server now has it, in its place.
  const control = useMutation({
    mutationFn: ({ action, broadcast }: { action: Control; broadcast: BroadcastRow }) => api.post(`/broadcasts/${broadcast.id}/${action}`),
    onSuccess: ({ broadcast }, { action }) => {
      list.replace(broadcast)
      toast.success({ pause: 'ارسال متوقف شد', resume: 'ارسال ادامه پیدا کرد', cancel: 'ارسال لغو شد' }[action])
      setConfirming(null)
    },
    onError: refused,
  })

  // «لغو پین» is a run of its own, on top of the list — read again, with its source's row.
  const unpin = useMutation({
    mutationFn: (broadcast: BroadcastRow) => api.post(`/broadcasts/${broadcast.id}/unpin`),
    meta: { invalidates: [queryKeys.broadcasts] },
    onSuccess: () => {
      toast.success('لغو پین شروع شد؛ ربات پیام‌ها را یکی‌یکی از حالت پین درمی‌آورد')
      setConfirming(null)
    },
    onError: refused,
  })
  const busy = control.isPending || unpin.isPending

  return (
    <>
      <ListView
        list={list}
        noun="ارسال‌ها"
        unit="ارسال"
        skeletonRows={4}
        empty={<EmptyState framed icon={Megaphone} title="هنوز ارسالی نشده است" description="مدیرهای ربات با /broadcast در ربات پیام همگانی می‌فرستند؛ هر ارسال با پیشرفتش این‌جا می‌آید." />}
      >
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>پیام</TableHead>
              <TableHead>مخاطب</TableHead>
              <TableHead>پیشرفت</TableHead>
              <TableHead>وضعیت</TableHead>
              <TableHead className="hidden lg:table-cell">فرستنده</TableHead>
              <TableHead className="w-10">
                <span className="sr-only">عملیات</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.rows.map((broadcast) => (
              <TableRow key={broadcast.id}>
                <TableCell className="max-w-72">
                  <MessageCell broadcast={broadcast} />
                </TableCell>
                <TableCell>{broadcast.kind === 'unpin' ? <span className="text-muted-foreground">همان گیرنده‌ها</span> : broadcast.audience.label}</TableCell>
                <TableCell className="min-w-44">
                  <Progress broadcast={broadcast} />
                </TableCell>
                <TableCell>
                  <StatusBadge status={BROADCAST_STATUS[broadcast.status]} />
                </TableCell>
                <TableCell className="hidden text-footnote text-muted-foreground lg:table-cell">
                  {/* The space keeps who and when words apart for assistive tech, not one. */}
                  <div className="grid">
                    {broadcast.admin ? <UserIdentity user={broadcast.admin} /> : broadcast.reviewer && <Reviewer name={broadcast.reviewer} />}{' '}
                    <time dateTime={broadcast.created_at} title={formatDate(broadcast.created_at)}>
                      {timeAgo(broadcast.created_at)}
                    </time>
                  </div>
                </TableCell>
                <TableCell>
                  <Controls
                    broadcast={broadcast}
                    busy={busy}
                    onAct={(action) => (action === 'cancel' || action === 'unpin' ? setConfirming({ action, broadcast }) : control.mutate({ action, broadcast }))}
                  />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </ListView>

      <ConfirmModal
        open={confirming !== null}
        onClose={() => setConfirming(null)}
        title={confirming?.action === 'unpin' ? 'لغو پین' : 'لغو ارسال'}
        description={confirming ? `ارسال ${idLabel(confirming.broadcast.id)}` : undefined}
        confirmLabel={confirming?.action === 'unpin' ? 'لغو پین' : 'لغو ارسال'}
        destructive={confirming?.action === 'cancel'}
        pending={busy}
        onConfirm={() => confirming && (confirming.action === 'unpin' ? unpin.mutate(confirming.broadcast) : control.mutate({ action: 'cancel', broadcast: confirming.broadcast }))}
      >
        {confirming?.action === 'unpin'
          ? `پیام این ارسال از حالت پین در ${formatNumber(confirming.broadcast.pinned)} گفتگو درمی‌آید؛ خود پیام‌ها می‌مانند.`
          : 'بقیه گیرنده‌ها پیام را نمی‌گیرند؛ کسانی که گرفته‌اند همان را دارند. این کار برگشت ندارد.'}
      </ConfirmModal>
    </>
  )
}

/** What went: its kind and the start of its text, how (a copy or a forward), pinned, with buttons. */
function MessageCell({ broadcast }: { broadcast: BroadcastRow }) {
  if (broadcast.kind === 'unpin') {
    return (
      <span className="flex items-center gap-2">
        <PinOff className="size-4 shrink-0 text-muted-foreground" aria-hidden />
        <span>
          لغو پین ارسال <bdi dir="ltr">#{broadcast.source_id}</bdi>
        </span>
      </span>
    )
  }

  const content = (broadcast.content && CONTENT[broadcast.content]) || OTHER
  const Icon = content.icon
  const buttons = broadcast.buttons.reduce((sum, row) => sum + row.length, 0)

  return (
    <div className="grid min-w-0 gap-1">
      <span className="flex min-w-0 items-start gap-2">
        <Icon className="mt-[3px] size-4 shrink-0 text-muted-foreground" aria-hidden />
        <span className="shrink-0 font-medium">{content.label}</span>
        {/* The words it was captured with, in two lines at most (the whole message is the admin's own, in Telegram). */}
        {broadcast.excerpt && (
          <span className="line-clamp-2 max-w-80 min-w-0 wrap-break-word whitespace-normal text-muted-foreground" dir="auto">
            {broadcast.excerpt}
          </span>
        )}
      </span>
      <span className="flex flex-wrap items-center gap-1">
        <Chip>{broadcast.mode === 'forward' ? 'فوروارد' : 'کپی'}</Chip>
        {broadcast.pin && (
          <Chip>
            <Pin className="size-3" aria-hidden />
            پین
          </Chip>
        )}
        {buttons > 0 && <Chip>{formatNumber(buttons)} دکمه</Chip>}
        <span className="text-caption text-muted-foreground" dir="ltr">
          #{broadcast.id}
        </span>
      </span>
    </div>
  )
}

function Chip({ children }: { children: ReactNode }) {
  return (
    <Badge variant="outline" className="font-normal">
      {children}
    </Badge>
  )
}

/** How far it got: a bar, «۴۵۰ از ۱۲۰۰», and what became of them. */
function Progress({ broadcast }: { broadcast: BroadcastRow }) {
  const reached = Math.min(broadcast.sent + broadcast.blocked + broadcast.failed, broadcast.total)
  const parts =
    broadcast.kind === 'unpin'
      ? [`${formatNumber(broadcast.sent)} برداشته شد`, broadcast.failed > 0 && `${formatNumber(broadcast.failed)} نشد`]
      : [`${formatNumber(broadcast.sent)} رسید`, broadcast.blocked > 0 && `${formatNumber(broadcast.blocked)} مسدود`, broadcast.failed > 0 && `${formatNumber(broadcast.failed)} نرسید`]

  return (
    <div className="grid gap-1">
      <ProgressBar label="پیشرفت" value={reached} max={broadcast.total} tone={broadcast.status === 'paused' ? 'warning' : 'info'} />
      <span className="text-footnote text-muted-foreground tabular">
        {formatNumber(reached)} از {formatNumber(broadcast.total)} · {parts.filter(Boolean).join(' · ')}
      </span>
    </div>
  )
}

/** The row's menu: the operations its state allows; none, no menu. */
function Controls({ broadcast, busy, onAct }: { broadcast: BroadcastRow; busy: boolean; onAct: (action: Action) => void }) {
  const { actions } = broadcast
  if (!actions.pause && !actions.resume && !actions.cancel && !actions.unpin) return null

  return (
    <RowMenu label={`ارسال ${idLabel(broadcast.id)}`}>
      {actions.pause && (
        <DropdownMenuItem disabled={busy} onSelect={() => onAct('pause')}>
          <Pause aria-hidden />
          توقف
        </DropdownMenuItem>
      )}
      {actions.resume && (
        <DropdownMenuItem disabled={busy} onSelect={() => onAct('resume')}>
          <Play aria-hidden />
          ادامه
        </DropdownMenuItem>
      )}
      {actions.unpin && (
        <DropdownMenuItem disabled={busy} onSelect={() => onAct('unpin')}>
          <PinOff aria-hidden />
          لغو پین
        </DropdownMenuItem>
      )}
      {actions.cancel && (
        <>
          <DropdownMenuSeparator />
          <DropdownMenuItem variant="destructive" disabled={busy} onSelect={() => onAct('cancel')}>
            <Ban aria-hidden />
            لغو ارسال
          </DropdownMenuItem>
        </>
      )}
    </RowMenu>
  )
}
