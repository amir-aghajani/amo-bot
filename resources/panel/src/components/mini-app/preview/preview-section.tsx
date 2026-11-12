import { useEffect, useId, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { CircleAlert, Eye, Moon, RefreshCw, Sun, TimerOff, UserSearch } from 'lucide-react'
import { Callout } from '@/components/callout'
import { FormError } from '@/components/form-footer'
import { CustomerPicker } from '@/components/mini-app/preview/customer-picker'
import { DEVICES, TELEGRAM_THEMES, WEB_APP_PLATFORM, WEB_APP_VERSION, type Device, type TelegramTheme } from '@/components/mini-app/preview/telegram'
import { PhoneAction, PhoneMessage, TelegramPhone } from '@/components/mini-app/preview/telegram-phone'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { api, ApiError } from '@/lib/api'
import type { MiniAppSettings, UserRow } from '@/lib/api-types'
import { messageOf } from '@/lib/failure'
import { formatDate } from '@/lib/format'

/** How long a preview lasts (the server's: `POST /mini-app/preview`). */
const PREVIEW_MS = 30 * 60_000

const THEMES: PageTab<TelegramTheme>[] = [
  { value: 'light', label: 'روشن', icon: Sun },
  { value: 'dark', label: 'تیره', icon: Moon },
]

const SIZES: PageTab<Device>[] = (Object.keys(DEVICES) as Device[]).map((device) => ({ value: device, label: DEVICES[device].label }))

/** An open preview: its token, the app's address on this site, and when it ends (by this browser's clock). */
interface Session {
  token: string
  path: string
  endsAt: number
}

/**
 * When a preview ends, by this browser's clock: the server's end — or, read against a clock far off (an end already
 * past, or further away than a preview lasts), PREVIEW_MS from now.
 */
function endOf(expiresAt: string): number {
  const left = Date.parse(expiresAt) - Date.now()
  return Date.now() + (left > 0 && left <= PREVIEW_MS ? left : PREVIEW_MS)
}

/**
 * Where the frame opens the app: its address, and in its fragment what Telegram hands a mini app as it launches it —
 * here the preview's token in place of the launch data, the Bot API version, a phone, and the theme picked.
 */
function launchAddress(path: string, token: string, theme: TelegramTheme): string {
  const parameters: [string, string][] = [
    ['tgWebAppPreview', token],
    ['tgWebAppVersion', WEB_APP_VERSION],
    ['tgWebAppPlatform', WEB_APP_PLATFORM],
    ['tgWebAppThemeParams', JSON.stringify(TELEGRAM_THEMES[theme])],
  ]

  return `${path}#${parameters.map(([name, value]) => `${name}=${encodeURIComponent(value)}`).join('&')}`
}

interface PreviewSectionProps {
  miniApp: MiniAppSettings
  /** How many times a card saved the settings on the page: each opens the app again with them (a read again does not). */
  saves: number
}

/**
 * «پیش‌نمایش»: the mini app as one of the shop's customers sees it, on a phone drawn as Telegram draws it
 * (TelegramPhone) — the customer picked, then a read-only session for them (`POST /mini-app/preview`, 30 minutes, even
 * while the app is off), in Telegram's light or dark theme, on a small phone or a large one. The app opens again — the
 * same session — as the theme or the phone changes, as a card saves the settings, and as it closes; «باز کردن دوباره»
 * opens a new session. Once the session ends, a cover over the phone says so and opens a new one.
 */
export function PreviewSection({ miniApp, saves }: PreviewSectionProps) {
  const pickerId = useId()
  const [customer, setCustomer] = useState<UserRow | null>(null)
  // Telegram's theme as the panel's own to start with: dark beside a dark panel.
  const [theme, setTheme] = useState<TelegramTheme>(() => (document.documentElement.classList.contains('dark') ? 'dark' : 'light'))
  const [device, setDevice] = useState<Device>('small')
  const [session, setSession] = useState<Session | null>(null)
  // The app closed by itself or by Telegram's close: counted, each one opening it again.
  const [closes, setCloses] = useState(0)
  const [endedFor, setEndedFor] = useState<string | null>(null)

  const opening = useMutation({
    mutationFn: (user: UserRow) => api.post('/mini-app/preview', { user_id: user.id }),
    meta: { quiet: true },
  })

  const open = (user: UserRow) =>
    opening.mutate(user, {
      onSuccess: ({ preview }) => setSession({ token: preview.token, path: preview.path, endsAt: endOf(preview.expires_at) }),
    })

  const pick = (user: UserRow | null) => {
    setCustomer(user)
    setSession(null)
    opening.reset()
    if (user !== null) open(user)
  }

  const again = () => {
    if (customer !== null) open(customer)
  }

  useEffect(() => {
    if (session === null) return
    const timer = window.setTimeout(() => setEndedFor(session.token), Math.max(0, session.endsAt - Date.now()))
    return () => window.clearTimeout(timer)
  }, [session])

  const ended = session !== null && endedFor === session.token
  const refusal = opening.error instanceof ApiError ? opening.error.field('user_id') : undefined
  const failure = opening.error !== null && refusal === undefined ? messageOf(opening.error) : null

  const cover =
    customer === null ? (
      <PhoneMessage icon={UserSearch} title="یک مشتری انتخاب کنید" description="مینی‌اپ با حساب او باز می‌شود، همان‌طور که در تلگرام خودش می‌بیند." />
    ) : session === null ? (
      opening.isError ? (
        <PhoneMessage icon={CircleAlert} title="پیش‌نمایش باز نشد" description={refusal ?? failure} />
      ) : (
        <PhoneMessage busy title="در حال باز کردن پیش‌نمایش…" />
      )
    ) : ended ? (
      <PhoneMessage
        icon={TimerOff}
        title="زمان پیش‌نمایش تمام شد"
        description="هر پیش‌نمایش ۳۰ دقیقه باز می‌ماند؛ برای دیدن دوباره، آن را دوباره باز کنید."
        action={
          <PhoneAction onClick={again} busy={opening.isPending}>
            باز کردن دوباره
          </PhoneAction>
        }
      />
    ) : null

  return (
    <div className="@container">
      <div className="grid grid-cols-[minmax(0,1fr)] items-start gap-6 @2xl:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]">
        <Card>
          <CardHeader className="pb-4">
            <CardHeading>
              <CardTitle className="text-heading">پیش‌نمایش مینی‌اپ</CardTitle>
              <CardDescription>مینی‌اپ را همان‌طور ببینید که یک مشتری در تلگرامش می‌بیند: با حساب، سرویس‌ها و کیف پول خود او.</CardDescription>
            </CardHeading>
          </CardHeader>
          <CardContent className="grid gap-5 pb-5">
            <CustomerPicker id={pickerId} picked={customer} onPick={pick} error={refusal} />
            <div className="grid gap-1.5">
              <span className="text-body font-medium">تم تلگرام</span>
              <PageTabs as="choice" value={theme} onChange={setTheme} tabs={THEMES} aria-label="تم تلگرام" size="sm" className="w-fit" />
            </div>
            <div className="grid gap-1.5">
              <span className="text-body font-medium">اندازه گوشی</span>
              <PageTabs as="choice" value={device} onChange={setDevice} tabs={SIZES} aria-label="اندازه گوشی" size="sm" className="w-fit" />
            </div>
            <div className="grid gap-2">
              <Button variant="secondary" icon={RefreshCw} busy={opening.isPending && customer !== null} disabled={customer === null} onClick={again} className="w-fit">
                باز کردن دوباره
              </Button>
              {session !== null && <FormError message={failure} failure={opening.error} />}
            </div>
            <Callout tone="info" icon={Eye}>
              پیش‌نمایش فقط نشان می‌دهد: در آن خرید، پرداخت، تیکت یا هیچ تغییری انجام نمی‌شود. هر بار ۳۰ دقیقه باز می‌ماند
              {session !== null && !ended ? `؛ این یکی تا ساعت ${formatDate(session.endsAt, { timeStyle: 'short' })}.` : '.'}
              {!miniApp.enabled && <span className="mt-1 block text-muted-foreground">مینی‌اپ خاموش است، ولی پیش‌نمایش با این حال کار می‌کند؛ مشتری‌ها چیزی نمی‌بینند.</span>}
            </Callout>
          </CardContent>
        </Card>

        <div className="min-w-0 @2xl:sticky @2xl:top-[calc(var(--topbar-height)+1.5rem)]">
          <TelegramPhone
            // A fresh frame for each launch: a new session, a theme, a phone, the settings saved, the app closed.
            key={`${session?.token ?? 'none'}:${closes}:${theme}:${device}:${saves}`}
            device={device}
            theme={theme}
            title={miniApp.title ?? miniApp.shop_name}
            src={session === null ? null : launchAddress(session.path, session.token, theme)}
            onClose={() => setCloses((count) => count + 1)}
            cover={cover}
          />
        </div>
      </div>
    </div>
  )
}
