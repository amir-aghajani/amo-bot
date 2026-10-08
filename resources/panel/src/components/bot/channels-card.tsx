import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Megaphone, Plus, RefreshCw, Trash2, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { EmptyState } from '@/components/empty-state'
import { Field } from '@/components/field'
import { IconButton } from '@/components/icon-button'
import { ListView } from '@/components/list-view'
import { ActionsHead, OrderCell, OrderHead, RemoveConfirm } from '@/components/sortable-list'
import { TextLink } from '@/components/text-link'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { BotChannelRow } from '@/lib/api-types'
import { timeAgo } from '@/lib/format'
import { botChannelsQuery } from '@/lib/queries'
import { useForm } from '@/lib/use-form'
import { useRows } from '@/lib/use-rows'

/**
 * The channels a customer must join before the bot serves them. A channel is added by its link; the
 * server looks it up and refuses it unless the bot is an admin there (it needs that to see the members).
 * List operations apply at once — the rule itself is the switch on the card above it (the channels section).
 */
export function ChannelsCard({ required }: { required: boolean }) {
  const list = useRows({
    query: botChannelsQuery,
    list: 'channels',
    reorder: (ids) => api.post('/bot/channels/reorder', { ids }),
    remove: (channel) => api.delete(`/bot/channels/${channel.id}`),
  })
  const channels = list.rows
  const [removing, setRemoving] = useState<BotChannelRow | null>(null)

  const check = useMutation({
    mutationFn: (channel: BotChannelRow) => api.post(`/bot/channels/${channel.id}/check`),
    onSuccess: ({ channel }) => {
      list.upsert(channel)
      if (channel.bot_is_admin) toast.success(`ربات در «${channel.title}» مدیر است`)
      else toast.error(`ربات دیگر در «${channel.title}» مدیر نیست`)
    },
  })

  const lostAdmin = channels.filter((c) => !c.bot_is_admin)

  return (
    <Card>
      <CardHeader className="border-b border-border pb-4">
        <CardHeading>
          <CardTitle>کانال‌های اجباری</CardTitle>
          <CardDescription>
            لینک کانال یا گروه را وارد کنید. ربات باید قبلا مدیر آن‌جا شده باشد تا بتواند عضویت مشتری‌ها را ببیند.
            {required ? (channels.length === 0 ? ' تا وقتی این لیست خالی است، چیزی از مشتری خواسته نمی‌شود.' : '') : ' فعلا کلید «عضویت اجباری در کانال» خاموش است و این لیست اعمال نمی‌شود.'}
          </CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent className="grid gap-5 pt-5">
        {lostAdmin.length > 0 && (
          <Callout tone="warning" icon={TriangleAlert}>
            ربات در {lostAdmin.map((c) => `«${c.title}»`).join('، ')} مدیر نیست؛ تا وقتی درست نشود، آن کانال بررسی نمی‌شود و جلوی کسی را نمی‌گیرد.
          </Callout>
        )}

        <AddChannelForm onAdded={list.upsert} />

        <ListView
          list={list}
          noun="کانال‌ها"
          skeletonRows={2}
          empty={
            <EmptyState
              compact
              framed
              icon={Megaphone}
              title="هنوز کانالی اضافه نشده"
              description="اولین کانال را با لینکش اضافه کنید؛ مشتری قبل از استفاده از ربات باید عضو همه کانال‌های این لیست باشد."
            />
          }
        >
          <Table>
            <TableHeader>
              <TableRow>
                <OrderHead />
                <TableHead>کانال</TableHead>
                <TableHead className="hidden md:table-cell">وضعیت ربات</TableHead>
                <ActionsHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {channels.map((channel, index) => (
                <TableRow key={channel.id}>
                  <OrderCell list={list} index={index} label={channel.title} />
                  <TableCell>
                    <div className="grid">
                      <span className="font-medium">{channel.title}</span>
                      <TextLink href={channel.link} className="w-fit truncate text-footnote">
                        <bdi dir="ltr">{channel.username ? `@${channel.username}` : channel.link}</bdi>
                      </TextLink>
                    </div>
                  </TableCell>
                  <TableCell className="hidden md:table-cell">
                    <div className="grid justify-items-start gap-1">
                      <Badge variant={channel.bot_is_admin ? 'success' : 'warning'}>{channel.bot_is_admin ? 'مدیر است' : 'مدیر نیست'}</Badge>
                      <span className="text-footnote text-muted-foreground">
                        {channel.type === 'channel' ? 'کانال' : 'گروه'} · بررسی: {timeAgo(channel.checked_at)}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center justify-end gap-1">
                      <IconButton
                        aria-label={`بررسی دوباره ${channel.title}`}
                        title="بررسی دوباره"
                        className="size-7"
                        icon={RefreshCw}
                        busy={check.isPending && check.variables.id === channel.id}
                        // One check at a time; the button pressed keeps the focus meanwhile.
                        aria-disabled={check.isPending}
                        onClick={() => check.mutate(channel)}
                      />
                      <IconButton aria-label={`حذف ${channel.title}`} title="حذف" className="size-7 hover:text-danger" icon={Trash2} onClick={() => setRemoving(channel)} />
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </ListView>
      </CardContent>

      <RemoveConfirm
        row={removing}
        remove={list.remove}
        title="حذف کانال"
        description={(channel) => `«${channel.title}» از لیست کانال‌های اجباری برداشته می‌شود.`}
        details={() => 'ربات از کانال بیرون نمی‌رود؛ فقط عضویت در آن دیگر شرط استفاده از ربات نیست.'}
        removed="کانال حذف شد"
        onClose={() => setRemoving(null)}
      />
    </Card>
  )
}

/** One field: paste the link, the server does the rest. */
function AddChannelForm({ onAdded }: { onAdded: (channel: BotChannelRow) => void }) {
  const { values, set, reset, error, formError, busy, submit, handleSubmit } = useForm({ link: '' })

  const add = handleSubmit(async () => {
    const result = await submit(() => api.post('/bot/channels', values))
    if (!result) return
    onAdded(result.channel)
    reset({ link: '' })
    toast.success(`«${result.channel.title}» اضافه شد`)
  })

  return (
    <form onSubmit={add} noValidate className="grid gap-3">
      <Field
        id="channel_link"
        label="افزودن کانال"
        // Telegram not answering is about this one field too.
        error={error('link') ?? formError ?? undefined}
        hint="لینک عمومی یا نام کاربری (مثل https://t.me/mychannel یا @mychannel). برای کانال خصوصی شناسه عددی آن را بدهید؛ لینک عضویت را ربات خودش می‌سازد."
      >
        {(control) => (
          <div className="flex gap-2">
            <Input {...control} dir="ltr" autoComplete="off" spellCheck={false} placeholder="https://t.me/mychannel" value={values.link} onChange={(e) => set('link', e.target.value)} />
            <Button type="submit" variant="secondary" icon={Plus} busy={busy} disabled={values.link.trim() === ''} className="shrink-0">
              افزودن
            </Button>
          </div>
        )}
      </Field>
    </form>
  )
}
