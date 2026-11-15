import { useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, ExternalLink, Link2, Unlink } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { ConfirmModal } from '@/components/confirm-modal'
import { ErrorState } from '@/components/error-state'
import { Field } from '@/components/field'
import { useReadFailure } from '@/components/page'
import { SecretInput } from '@/components/secret-input'
import { useSectionShown } from '@/components/sectioned-page'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { api, ApiError } from '@/lib/api'
import type { OwnerTelegramAccount, OwnerTelegramLinkResponse, OwnerTelegramResponse } from '@/lib/api-types'
import { copyText } from '@/lib/clipboard'
import { handleLabel } from '@/lib/direction'
import { messageOf } from '@/lib/failure'
import { formatDate, formatNumber } from '@/lib/format'
import { queryKeys } from '@/lib/query-keys'

/** The most accounts linked at once (Telegram\Admin\OwnerLinks::MOST): a further one lets the oldest go. */
const MOST = 5

/** How often the accounts are read again while a link is open: the one it links shows up without a reload. */
const WATCH_MS = 5_000

/** An account as the card names it: its name in the main bot, else its @username, else that it has none yet. */
function accountName(account: OwnerTelegramAccount): string {
  return account.name ?? (account.username ? handleLabel(account.username) : 'حساب بی‌نام')
}

/** Where the focus goes once the card is drawn again, so it never falls to the page as the password's form comes and goes. */
type FocusTarget = 'make' | 'password' | 'link'

/**
 * «مدیریت از ربات» (the owner's, «تنظیمات پنل › ورود به پنل»): the Telegram accounts that are the owner in the main
 * bot's private chat — every section of every shop from «⚙️ مدیریت», as this login has in the panel —, a one-time link
 * that makes one more (the panel's password asked first, in place, so a session alone makes no account the owner; shown
 * once, while it may be opened: the server keeps its hash alone), and an account let go, asked first. Read while its
 * section is on screen; while a link is open, read again, so the account it links shows up.
 */
export function OwnerTelegramCard() {
  const queryClient = useQueryClient()
  // Read only while its section is on screen.
  const shownSection = useSectionShown()
  const read = useQuery({
    queryKey: queryKeys.ownerTelegram,
    queryFn: () => api.get<OwnerTelegramResponse>('/settings/owner-telegram'),
    enabled: shownSection,
    refetchInterval: (query) => (shownSection && query.state.data?.link_open_until ? WATCH_MS : false),
  })
  const failure = useReadFailure(read)
  const data = read.data
  // The link made here: shown while the server says it is the open one.
  const [link, setLink] = useState<OwnerTelegramLinkResponse['link'] | null>(null)
  const [dropping, setDropping] = useState<OwnerTelegramAccount | null>(null)
  const apply = ({ accounts, link_open_until }: OwnerTelegramResponse) => queryClient.setQueryData<OwnerTelegramResponse>(queryKeys.ownerTelegram, { accounts, link_open_until })

  // «ساخت لینک» opens the password's form; the link is asked with what is typed there.
  const [asking, setAsking] = useState(false)
  const [password, setPassword] = useState('')
  const focus = useRef<FocusTarget | null>(null)
  const makeButton = useRef<HTMLButtonElement>(null)
  const passwordInput = useRef<HTMLInputElement>(null)
  const linkBox = useRef<HTMLDivElement>(null)
  useLayoutEffect(() => {
    const wanted = focus.current
    if (wanted === null) return
    // The link made — else, a newer one made elsewhere having put it away, the button that makes another.
    const target = wanted === 'password' ? passwordInput.current : wanted === 'link' ? (linkBox.current ?? makeButton.current) : makeButton.current
    // Not drawn yet: the next drawing takes it.
    if (target === null) return
    focus.current = null
    target.focus()
  })

  const make = useMutation({
    mutationFn: (current_password: string) => api.post('/settings/owner-telegram/link', { current_password }),
    // The card says why it was refused: the password under its field, anything else (the main bot's username not known
    // yet, too many tries) above the buttons.
    meta: { quiet: true },
    onSuccess: (answer) => {
      apply(answer)
      setLink(answer.link)
      setAsking(false)
      setPassword('')
      focus.current = 'link'
      toast.success('لینک ساخته شد')
    },
    onError: (error) => {
      if (error instanceof ApiError && error.field('current_password') !== undefined) focus.current = 'password'
    },
  })
  const passwordRefused = make.error instanceof ApiError ? make.error.field('current_password') : undefined

  const ask = () => {
    make.reset()
    setPassword('')
    setAsking(true)
  }
  const cancel = () => {
    make.reset()
    setPassword('')
    setAsking(false)
    focus.current = 'make'
  }

  const drop = useMutation({
    mutationFn: (account: OwnerTelegramAccount) => api.delete(`/settings/owner-telegram/${account.telegram_id}`),
    // The bot admin role its link gave it goes too: the customers' lists and pages show it.
    meta: { quiet: true, invalidates: [queryKeys.users, queryKeys.everyUser] },
    onSuccess: (answer) => {
      apply(answer)
      setDropping(null)
      toast.success('حساب برداشته شد')
    },
  })

  // Not once it was opened or ran out, nor once a newer one was made elsewhere (another tab, the bot's own screen): that
  // one is the open one now, and this one works no more.
  const shown = link !== null && data?.link_open_until && Date.parse(data.link_open_until) === Date.parse(link.expires_at) ? link : null

  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">مدیریت از ربات</CardTitle>
          <CardDescription>
            حساب تلگرام خودتان را به ربات اصلی وصل کنید تا فروشگاه را از چت خصوصی ربات هم مدیریت کنید: دکمه «⚙️ مدیریت» یا <bdi dir="ltr">/admin</bdi> همه بخش‌های این پنل را، در هر فروشگاه، با منوهای
            ربات باز می‌کند. هر حساب وصل‌شده همه دسترسی‌های ورود به پنل را دارد؛ پس فقط حساب‌های خودتان را وصل کنید.
          </CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent className="grid gap-4">
        {!data ? (
          failure.error ? (
            <ErrorState what="حساب‌های تلگرام مالک" error={failure.error} onRetry={() => void read.refetch()} retrying={failure.retrying} />
          ) : (
            <Skeleton className="h-24 w-full" />
          )
        ) : (
          <>
            {data.accounts.length === 0 ? (
              <p className="text-body text-muted-foreground">هنوز حسابی وصل نشده است؛ با «ساخت لینک» حساب تلگرام خودتان را وصل کنید.</p>
            ) : (
              <ul className="divide-y divide-border rounded-lg border border-border" aria-label="حساب‌های وصل‌شده">
                {data.accounts.map((account) => (
                  <li key={account.telegram_id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                    <div className="grid min-w-0 flex-1 gap-0.5">
                      <span className="truncate text-body font-medium">
                        <bdi>{accountName(account)}</bdi>
                      </span>
                      <span className="text-footnote text-muted-foreground">
                        {account.name !== null && account.username && <>{handleLabel(account.username)} · </>}
                        شناسه تلگرام <bdi dir="ltr">{account.telegram_id}</bdi>
                      </span>
                    </div>
                    <Button
                      size="sm"
                      variant="danger-outline"
                      icon={Unlink}
                      onClick={() => {
                        drop.reset()
                        setDropping(account)
                      }}
                      aria-label={`برداشتن ${accountName(account)}`}
                    >
                      برداشتن
                    </Button>
                  </li>
                ))}
              </ul>
            )}
            <p className="text-footnote text-faint">{`حداکثر ${formatNumber(MOST)} حساب وصل می‌ماند؛ با وصل شدن یک حساب بیشتر، قدیمی‌ترین برداشته می‌شود، با نقش مدیر رباتی که لینک به آن داده بود. عوض کردن نام کاربری یا رمز عبور پنل همه حساب‌ها را برمی‌دارد.`}</p>

            {shown ? (
              <Callout tone="info" icon={Link2} role="status">
                <div ref={linkBox} tabIndex={-1} className="grid gap-2 rounded-md outline-none focus-visible:focus-ring">
                  <p>
                    <span className="font-medium">این لینک را با همان حساب تلگرامی باز کنید که باید مالک شود.</span> فقط یک بار و تا {formatDate(shown.expires_at)} کار می‌کند و لینک تازه آن را از کار
                    می‌اندازد؛ بعد از ترک این صفحه دوباره نشان داده نمی‌شود.
                  </p>
                  <code dir="ltr" className="block rounded-md border border-border bg-background px-2 py-1.5 text-footnote break-all select-all">
                    {shown.url}
                  </code>
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="secondary" icon={Copy} onClick={() => void copyText(shown.url, 'لینک کپی شد')}>
                      کپی لینک
                    </Button>
                    <Button size="sm" variant="secondary" asChild>
                      <a href={shown.url} target="_blank" rel="noreferrer">
                        <ExternalLink aria-hidden />
                        باز کردن در تلگرام
                      </a>
                    </Button>
                  </div>
                </div>
              </Callout>
            ) : (
              data.link_open_until && (
                <p className="text-footnote text-muted-foreground">
                  {`یک لینک تا ${formatDate(data.link_open_until)} باز است. لینک فقط موقع ساختن نشان داده می‌شود؛ اگر آن را ندارید، لینک تازه بسازید.`}
                </p>
              )
            )}

            {make.error && passwordRefused === undefined && (
              <Callout tone="danger" role="alert">
                {messageOf(make.error)}
              </Callout>
            )}

            {asking ? (
              <form
                className="grid gap-3 rounded-lg border border-border p-3"
                aria-label="رمز عبور برای ساخت لینک"
                onSubmit={(event) => {
                  event.preventDefault()
                  if (!make.isPending) make.mutate(password)
                }}
              >
                <p className="text-body">برای ساخت لینک، رمز ورود پنل را وارد کنید.</p>
                <Field id="owner_telegram_password" label="رمز عبور فعلی" error={passwordRefused}>
                  <SecretInput
                    ref={passwordInput}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Escape' && !make.isPending) cancel()
                    }}
                    autoComplete="current-password"
                    autoFocus
                    className="sm:max-w-72"
                  />
                </Field>
                <div className="flex flex-wrap gap-2">
                  <Button type="submit" icon={Link2} busy={make.isPending}>
                    {data.link_open_until ? 'ساخت لینک تازه' : 'ساخت لینک'}
                  </Button>
                  <Button variant="secondary" disabled={make.isPending} onClick={cancel}>
                    انصراف
                  </Button>
                </div>
              </form>
            ) : (
              <div className="flex flex-wrap gap-2">
                <Button ref={makeButton} variant={data.accounts.length === 0 ? 'default' : 'secondary'} icon={Link2} onClick={ask}>
                  {/* One open, shown here or not: the new one ends it, as the note above says. */}
                  {data.link_open_until ? 'ساخت لینک تازه' : 'ساخت لینک'}
                </Button>
              </div>
            )}
          </>
        )}
      </CardContent>

      <ConfirmModal
        open={dropping !== null}
        onClose={() => setDropping(null)}
        title="برداشتن حساب"
        description={dropping ? accountName(dropping) : undefined}
        confirmLabel="برداشتن"
        destructive
        pending={drop.isPending}
        error={drop.error ? messageOf(drop.error) : null}
        onConfirm={() => dropping && drop.mutate(dropping)}
      >
        این حساب دیگر مالک نیست و نقش مدیر رباتی که لینک به آن داده بود هم برداشته می‌شود: از پیام بعدی‌اش، مدیریت فروشگاه در ربات برایش بسته می‌شود. حسابی که پیش از وصل شدن مدیر ربات بوده، مدیر ربات
        می‌ماند و تا «مدیران ربات» روشن است کارهای روزانه را دارد. هر وقت بخواهید، با لینک تازه دوباره وصلش کنید.
      </ConfirmModal>
    </Card>
  )
}
