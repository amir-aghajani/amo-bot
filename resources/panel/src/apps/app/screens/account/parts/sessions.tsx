import type { CSSProperties } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Monitor, Smartphone } from 'lucide-react'
import { keys, queryClient } from '@/apps/app/lib/query'
import { store } from '@/apps/app/lib/store'
import { confirmBox } from '@/apps/app/telegram/webapp'
import { Button } from '@/apps/app/ui/button'
import { Section } from '@/apps/app/ui/section'
import { ErrorView, Skeleton } from '@/apps/app/ui/states'
import { told } from '@/apps/app/ui/toaster'
import { Badge, IconTile } from '@/apps/app/ui/visuals'
import type { StoreSession, StoreSessionsResponse } from '@/lib/api-types'
import { formatDate, timeAgo } from '@/lib/format'

/*
 * «دستگاه‌ها»: where the account is signed in — the bot's mini app, the shop's website in a browser —, newest first: each
 * device as its browser named it, the address it signed in from and when it was last used, this one marked. Any other
 * can be ended (`DELETE /me/sessions/{id}`), which signs that device out; this one is not offered, since Telegram signs
 * the app in again each time it opens.
 */

/** Whether the words its browser gave name a phone (the mini app is on one). */
function onPhone(device: string | null): boolean {
  return /android|ios|iphone|ipad|mobile|تلگرام|telegram/i.test(device ?? '')
}

/** The hairline under a device starts where its words do: past its 40px icon and the 12px after it. */
const ROW_INSET = { '--row-inset': '4.25rem' } as CSSProperties

function SessionRow({ session, ending, onEnd }: { session: StoreSession; ending: boolean; onEnd: () => void }) {
  const used = session.last_used_at ?? session.created_at

  return (
    <li className="list-row flex min-h-16 items-center gap-3 px-4 py-3" style={ROW_INSET}>
      <IconTile icon={onPhone(session.device) ? Smartphone : Monitor} size="lg" look="soft" round />
      <span className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className="flex items-center gap-2">
          <span className="truncate text-body font-medium">{session.device ?? 'دستگاه ناشناس'}</span>
          {session.current && <Badge tone="success">همین دستگاه</Badge>}
        </span>
        <span className="text-hint flex flex-wrap gap-x-1.5 text-caption">
          {session.ip !== null && <span className="ltr">{session.ip}</span>}
          {session.ip !== null && <span aria-hidden>·</span>}
          <span title={formatDate(used)}>{session.current ? 'در حال استفاده' : `آخرین استفاده ${timeAgo(used)}`}</span>
        </span>
      </span>
      {!session.current && (
        <Button variant="danger" size="sm" busy={ending} onClick={onEnd}>
          خروج
        </Button>
      )}
    </li>
  )
}

export function SessionsSection() {
  const sessions = useQuery({ queryKey: keys.sessions, queryFn: () => store.get<StoreSessionsResponse>('/me/sessions') })
  const end = useMutation({
    mutationFn: (id: number) => store.delete(`/me/sessions/${id}`),
    onSuccess: (_, id) => {
      queryClient.setQueryData<StoreSessionsResponse>(keys.sessions, (list) => (list === undefined ? list : { sessions: list.sessions.filter((session) => session.id !== id) }))
      void queryClient.invalidateQueries({ queryKey: keys.sessions })
      told('آن دستگاه از حساب خارج شد.')
    },
  })

  const ask = async (session: StoreSession) => {
    if (await confirmBox(`«${session.device ?? 'دستگاه ناشناس'}» از حساب شما خارج شود؟`)) end.mutate(session.id)
  }

  return (
    <Section title="دستگاه‌ها" footer="دستگاهی را نمی‌شناسید؟ آن را از حساب خارج کنید.">
      {sessions.isError ? (
        <ErrorView error={sessions.error} retry={() => void sessions.refetch()} />
      ) : sessions.data === undefined ? (
        <div className="flex flex-col gap-3 p-4">
          <Skeleton className="h-10" />
          <Skeleton className="h-10" />
        </div>
      ) : (
        <ul>
          {sessions.data.sessions.map((session) => (
            <SessionRow key={session.id} session={session} ending={end.isPending && end.variables === session.id} onEnd={() => void ask(session)} />
          ))}
        </ul>
      )}
    </Section>
  )
}
