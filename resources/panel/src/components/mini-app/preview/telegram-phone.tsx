import { useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent, type ReactNode, type RefObject, type SyntheticEvent } from 'react'
import { ArrowRight, BatteryFull, Ellipsis, EllipsisVertical, Fingerprint, LoaderCircle, Signal, Vibrate, Wifi, X, type LucideIcon } from 'lucide-react'
import { BEZEL, CONTROLS, DEVICES, GESTURE_BAR, HEADER, isLight, STATUS_BAR, TELEGRAM_THEMES, type Device, type TelegramTheme } from '@/components/mini-app/preview/telegram'
import { useWebAppBridge, type BottomButton, type Bridge, type Prompt } from '@/components/mini-app/preview/use-web-app-bridge'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'

interface TelegramPhoneProps {
  device: Device
  theme: TelegramTheme
  /** The app's name in Telegram's header. */
  title: string
  /** The app's address with its launch parameters; null while there is none (a `cover` says why). */
  src: string | null
  /** The app closed — Telegram's close, the app's own, ⋮ › «بارگذاری دوباره» —: the caller opens it again (a new key). */
  onClose: () => void
  /** Drawn over the screen: what comes first while there is no app, or — over the app — why it stopped. */
  cover?: ReactNode
}

/** The room the page keeps above and below the phone: the topbar and the page's margins. */
const ROOM_AROUND = 104

/** The smallest a phone is drawn to fit the window's height (its width always fits). */
const SMALLEST = 0.6

/** The phone's corners: the frame's, and the screen's inside it. */
const FRAME_RADIUS = 46
const SCREEN_RADIUS = FRAME_RADIUS - BEZEL

/**
 * The mini app on a phone as Telegram 8.0 draws it on Android: the status bar, Telegram's header — its close (or the
 * app's back), the app's title, ⋮ — and, full screen, its floating controls over the app in the header's place; the
 * main and secondary buttons under the app, its popups and the fingerprint's prompts over it, a vibration's words a
 * moment. The app runs in a frame and talks to Telegram through useWebAppBridge. The phone is drawn at its size, made
 * smaller to fit the column's width and the window's height.
 */
export function TelegramPhone({ device, theme, title, src, onClose, cover }: TelegramPhoneProps) {
  const frame = useRef<HTMLIFrameElement>(null)
  const colours = TELEGRAM_THEMES[theme]
  const bridge = useWebAppBridge({ frame, src, theme: colours, onClose })
  const [menu, setMenu] = useState(false)
  // ⋮ — the header's, or the floating one full screen —: the focus comes back to it as the menu closes.
  const menuButton = useRef<HTMLButtonElement>(null)
  const { width, height } = DEVICES[device]
  const outer = { width: width + 2 * BEZEL, height: height + 2 * BEZEL }
  const [fit, scale] = useFit(outer.width, outer.height)

  // Each launch starts as Telegram starts a mini app: none of what telegram-web-app.js kept in the tab's session storage
  // from the last one (the frame is this site's, so its storage is the panel's) — it would start full screen, say.
  useLayoutEffect(() => {
    if (src !== null) forgetLaunch()
  }, [src])

  const background = bridge.backgroundColor ?? colours.bg_color
  const header = bridge.headerColor ?? colours.header_bg_color
  const bottomBar = bridge.bottomBarColor ?? colours.bottom_bar_bg_color
  const bottomShown = bridge.main !== null || bridge.secondary !== null
  // Where Telegram's parts end at the top: the menu and a vibration's words go under them.
  const chrome = STATUS_BAR + (bridge.fullscreen ? CONTROLS : HEADER)
  const screen = {
    '--wa-bg': background,
    '--wa-section': colours.section_bg_color,
    '--wa-text': colours.text_color,
    '--wa-hint': colours.hint_color,
    '--wa-button': colours.button_color,
    '--wa-button-text': colours.button_text_color,
    '--wa-destructive': colours.destructive_text_color,
    background,
  } as CSSProperties
  const openMenu = () => setMenu(true)
  const closeMenu = () => {
    setMenu(false)
    menuButton.current?.focus()
  }
  const inside = cover !== undefined && cover !== null

  return (
    <div ref={fit} className="flex w-full justify-center">
      <div className="relative shrink-0" style={{ width: outer.width * scale, height: outer.height * scale }}>
        <figure
          aria-label="مینی‌اپ در گوشی"
          className="absolute top-0 left-0 m-0 origin-top-left bg-[#0b0b0d] shadow-[0_32px_64px_-32px_rgb(0_0_0/0.6)] ring-1 ring-black/10 dark:ring-white/15"
          style={{ width: outer.width, height: outer.height, padding: BEZEL, borderRadius: FRAME_RADIUS, transform: scale === 1 ? undefined : `scale(${scale})` }}
        >
          {/* The phone's own keys, on its side. */}
          <span aria-hidden className="absolute top-32 -right-[3px] h-20 w-[3px] rounded-r-sm bg-[#1d1d20]" />
          <span aria-hidden className="absolute top-56 -right-[3px] h-12 w-[3px] rounded-r-sm bg-[#1d1d20]" />

          <div dir="rtl" className="relative flex size-full flex-col overflow-hidden font-sans" style={{ ...screen, borderRadius: SCREEN_RADIUS }}>
            <StatusBar background={bridge.fullscreen ? 'transparent' : header} ink={inkOn(bridge.fullscreen ? background : header)} over={bridge.fullscreen} />
            {!bridge.fullscreen && <Header bridge={bridge} title={title} colour={header} menu={menu} menuButton={menuButton} onMenu={openMenu} />}

            <div className="relative min-h-0 flex-1">
              {src !== null && (
                <iframe
                  ref={frame}
                  src={src}
                  title="مینی‌اپ"
                  allow="clipboard-write"
                  // As Telegram's web client frames a mini app: the app is this site's own page and keeps its origin (its
                  // storage, its API — scripts and the same origin both, which lets it lift the sandbox: it is our code);
                  // the sandbox only keeps the panel's tab where it is — a link the app opens goes to a new tab.
                  // oxlint-disable-next-line react/iframe-missing-sandbox
                  sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-downloads"
                  onLoad={plainScrollbars}
                  className="absolute inset-0 block size-full border-0"
                  style={{ background }}
                />
              )}
              {src === null && inside && <div className="absolute inset-0 flex items-center justify-center p-6">{cover}</div>}
            </div>

            {bottomShown && <BottomBar bridge={bridge} colour={bottomBar} />}
            <div aria-hidden className="flex shrink-0 items-center justify-center" style={{ height: GESTURE_BAR, background: bottomShown ? bottomBar : background }}>
              <span className="h-1 w-28 rounded-full opacity-40" style={{ background: inkOn(bottomShown ? bottomBar : background) }} />
            </div>

            {bridge.fullscreen && <FloatingControls bridge={bridge} menu={menu} menuButton={menuButton} onMenu={openMenu} />}
            {bridge.haptic && (
              <div
                key={bridge.haptic.id}
                role="status"
                className="pointer-events-none absolute left-1/2 z-30 flex -translate-x-1/2 animate-in items-center gap-1 rounded-full bg-black/75 px-2.5 py-1 text-[11px] text-white fade-in"
                style={{ top: chrome + 8 }}
              >
                <Vibrate className="size-3" aria-hidden />
                {bridge.haptic.label}
              </div>
            )}
            {menu && <Menu bridge={bridge} top={bridge.fullscreen ? chrome : STATUS_BAR + 6} onReload={onClose} onDone={closeMenu} />}
            {bridge.prompt && <PromptView prompt={bridge.prompt} onAnswer={bridge.answer} frame={frame} />}
            {src !== null && inside && <div className="absolute inset-0 z-50 flex items-center justify-center bg-black/45 p-6 backdrop-blur-[2px]">{cover}</div>}
          </div>
        </figure>
      </div>
    </div>
  )
}

/**
 * A word drawn on the phone's screen, in the Telegram theme's colours: a cover's (pick a customer, opening, ended), with
 * its button under it.
 */
export function PhoneMessage({ icon: Icon, busy = false, title, description, action }: { icon?: LucideIcon; busy?: boolean; title: string; description?: ReactNode; action?: ReactNode }) {
  return (
    <div role="status" className="grid w-full max-w-[17rem] justify-items-center gap-2 rounded-2xl p-5 text-center" style={{ background: 'var(--wa-section)', color: 'var(--wa-text)' }}>
      {busy ? (
        <LoaderCircle className="size-7 animate-spin" style={{ color: 'var(--wa-button)' }} aria-hidden />
      ) : (
        Icon && (
          <span
            aria-hidden
            className="flex size-12 items-center justify-center rounded-full"
            style={{ background: 'color-mix(in srgb, var(--wa-button) 14%, transparent)', color: 'var(--wa-button)' }}
          >
            <Icon className="size-6" />
          </span>
        )
      )}
      <p className="text-[15px] leading-6 font-semibold">{title}</p>
      {description && (
        <p className="text-[13px] leading-6" style={{ color: 'var(--wa-hint)' }}>
          {description}
        </p>
      )}
      {action && <div className="mt-2 w-full">{action}</div>}
    </div>
  )
}

/** A button on the phone's screen, as Telegram draws a mini app's main button. */
export function PhoneAction({ onClick, busy = false, children }: { onClick: () => void; busy?: boolean; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-disabled={busy}
      onClick={busy ? undefined : onClick}
      className="flex h-11 w-full items-center justify-center gap-2 rounded-xl text-[15px] font-semibold outline-none focus-visible:focus-ring active:brightness-90"
      style={{ background: 'var(--wa-button)', color: 'var(--wa-button-text)' }}
    >
      {busy && <LoaderCircle className="size-5 animate-spin" aria-hidden />}
      {children}
    </button>
  )
}

/**
 * The phone drawn at its size while it fits, made smaller to fit the width it has — and the window's height, down to
 * SMALLEST: the whole phone in sight.
 */
function useFit(width: number, height: number): [(element: HTMLDivElement | null) => void, number] {
  const [box, setBox] = useState<HTMLDivElement | null>(null)
  const [room, setRoom] = useState<{ width: number; height: number } | null>(null)

  useEffect(() => {
    if (!box) return
    const measure = () => setRoom({ width: box.clientWidth, height: window.innerHeight })
    measure()
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure)
    observer?.observe(box)
    window.addEventListener('resize', measure)
    return () => {
      observer?.disconnect()
      window.removeEventListener('resize', measure)
    }
  }, [box])

  if (room === null || room.width === 0) return [setBox, 1]
  return [setBox, Math.min(1, room.width / width, Math.max(SMALLEST, (room.height - ROOM_AROUND) / height))]
}

/** What Telegram draws over a colour: black on a light one, white on a dark one. */
function inkOn(colour: string): string {
  return isLight(colour) ? '#000000' : '#ffffff'
}

/** telegram-web-app.js's keys in the tab's session storage (`__telegram__…`), forgotten before the next launch. */
function forgetLaunch(): void {
  try {
    for (const key of Object.keys(sessionStorage)) {
      if (key.startsWith('__telegram__')) sessionStorage.removeItem(key)
    }
  } catch {
    // Storage refused: telegram-web-app.js could keep nothing there either.
  }
}

/** A phone draws no scrollbar over a page; a desktop browser would, inside the frame (the app is this site's page). */
function plainScrollbars(event: SyntheticEvent<HTMLIFrameElement>): void {
  try {
    const page = event.currentTarget.contentDocument
    if (!page) return
    const style = page.createElement('style')
    style.textContent = 'html { scrollbar-width: none; } ::-webkit-scrollbar { display: none; }'
    page.head.append(style)
  } catch {
    // A page of another site's: its own scrollbars.
  }
}

/** The clock, a minute at a time. */
function useNow(): Date {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 30_000)
    return () => window.clearInterval(timer)
  }, [])

  return now
}

/** The phone's status bar: the time, the camera, the signal and the battery — over the app while it is full screen. */
function StatusBar({ background, ink, over }: { background: string; ink: string; over: boolean }) {
  const now = useNow()

  return (
    <div
      aria-hidden
      dir="ltr"
      className={cn('z-20 flex shrink-0 items-center justify-between px-6 text-[12px] font-semibold', over ? 'absolute inset-x-0 top-0' : 'relative')}
      style={{ height: STATUS_BAR, background, color: ink }}
    >
      <span className="tabular">{formatDate(now, { timeStyle: 'short' })}</span>
      <span className="absolute top-1/2 left-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black ring-2 ring-[#151517]" />
      <span className="flex items-center gap-1">
        <Signal className="size-3.5" strokeWidth={2.5} />
        <Wifi className="size-3.5" strokeWidth={2.5} />
        <BatteryFull className="size-4" strokeWidth={2} />
      </span>
    </div>
  )
}

const ROUND = 'flex size-10 shrink-0 items-center justify-center rounded-full outline-none transition-colors hover:bg-current/10 focus-visible:focus-ring'

/** Telegram's header above an app that is not full screen: its close (the app's back while it shows one), the title, ⋮. */
function Header({
  bridge,
  title,
  colour,
  menu,
  menuButton,
  onMenu,
}: {
  bridge: Bridge
  title: string
  colour: string
  menu: boolean
  menuButton: RefObject<HTMLButtonElement | null>
  onMenu: () => void
}) {
  return (
    <div role="toolbar" aria-label="نوار تلگرام" className="flex shrink-0 items-center gap-1 px-1.5 transition-colors" style={{ height: HEADER, background: colour, color: inkOn(colour) }}>
      <button type="button" aria-label={bridge.back ? 'بازگشت' : 'بستن'} onClick={bridge.back ? bridge.pressBack : bridge.close} className={ROUND}>
        {bridge.back ? <ArrowRight className="size-5" aria-hidden /> : <X className="size-5" aria-hidden />}
      </button>
      <div className="grid min-w-0 flex-1 px-1">
        <span className="truncate text-[16px] leading-6 font-semibold">{title}</span>
        <span className="text-[12.5px] leading-4 opacity-60">مینی‌اپ</span>
      </div>
      <button ref={menuButton} type="button" aria-label="منوی مینی‌اپ" aria-haspopup="menu" aria-expanded={menu} onClick={onMenu} className={ROUND}>
        <EllipsisVertical className="size-5" aria-hidden />
      </button>
    </div>
  )
}

const PILL =
  'pointer-events-auto flex h-8 items-center rounded-full bg-black/35 text-[13px] font-medium text-white outline-none backdrop-blur-md transition-colors hover:bg-black/50 focus-visible:focus-ring'

/**
 * Telegram 8.0's controls over a full-screen app, in the header's place: «بستن» (the app's back while it shows one) at
 * one side and ⋯ at the other, under the status bar — the content safe area the app keeps clear of.
 */
function FloatingControls({ bridge, menu, menuButton, onMenu }: { bridge: Bridge; menu: boolean; menuButton: RefObject<HTMLButtonElement | null>; onMenu: () => void }) {
  return (
    <div role="toolbar" aria-label="دکمه‌های شناور تلگرام" className="pointer-events-none absolute inset-x-3 z-20 flex items-center justify-between" style={{ top: STATUS_BAR, height: CONTROLS }}>
      <button type="button" onClick={bridge.back ? bridge.pressBack : bridge.close} className={cn(PILL, 'gap-1 ps-2.5 pe-3')}>
        {bridge.back ? <ArrowRight className="size-4" aria-hidden /> : <X className="size-4" aria-hidden />}
        {bridge.back ? 'بازگشت' : 'بستن'}
      </button>
      <button ref={menuButton} type="button" aria-label="منوی مینی‌اپ" aria-haspopup="menu" aria-expanded={menu} onClick={onMenu} className={cn(PILL, 'w-11 justify-center')}>
        <Ellipsis className="size-5" aria-hidden />
      </button>
    </div>
  )
}

/** Where ↑/↓, Home and End move the focus among `count` items from the one at `at` (-1: none), round the ends; null for another key. */
function menuMove(key: string, at: number, count: number): number | null {
  if (count === 0) return null
  switch (key) {
    case 'ArrowDown':
      return at < 0 || at >= count - 1 ? 0 : at + 1
    case 'ArrowUp':
      return at <= 0 ? count - 1 : at - 1
    case 'Home':
      return 0
    case 'End':
      return count - 1
  }
  return null
}

/**
 * Telegram's ⋮ menu: the app's settings while it shows them, and the app loaded again — ↑/↓ (Home/End) along it, Escape
 * or a pick closing it (`onDone`, which gives the focus back to ⋮).
 */
function Menu({ bridge, top, onReload, onDone }: { bridge: Bridge; top: number; onReload: () => void; onDone: () => void }) {
  const item = 'px-4 py-2.5 text-start text-[14px] outline-none transition-colors hover:bg-current/10 focus-visible:bg-current/10'

  return (
    <>
      <button type="button" aria-label="بستن منو" tabIndex={-1} onClick={onDone} className="absolute inset-0 z-30 cursor-default" />
      <div
        role="menu"
        aria-label="منوی مینی‌اپ"
        onKeyDown={(event: KeyboardEvent<HTMLDivElement>) => {
          if (event.key === 'Escape') {
            event.preventDefault()
            onDone()
            return
          }
          const items = [...event.currentTarget.querySelectorAll<HTMLElement>('[role="menuitem"]')]
          const next = menuMove(event.key, items.indexOf(document.activeElement as HTMLElement), items.length)
          if (next === null) return
          event.preventDefault()
          items[next]?.focus()
        }}
        className="absolute end-2 z-30 grid min-w-44 animate-in overflow-hidden rounded-xl py-1 shadow-[0_8px_24px_rgb(0_0_0/0.28)] ring-1 ring-current/10 zoom-in-95 fade-in"
        style={{ top, background: 'var(--wa-section)', color: 'var(--wa-text)' }}
      >
        {/* Opened by a press, the menu's first item takes the focus, as a menu's does. */}
        {bridge.settings && (
          <button
            type="button"
            role="menuitem"
            autoFocus
            onClick={() => {
              onDone()
              bridge.pressSettings()
            }}
            className={item}
          >
            تنظیمات
          </button>
        )}
        <button
          type="button"
          role="menuitem"
          autoFocus={!bridge.settings}
          onClick={() => {
            onDone()
            onReload()
          }}
          className={item}
        >
          بارگذاری دوباره
        </button>
      </div>
    </>
  )
}

/** The bottom bar: the main button, and the secondary one at the side the app placed it. */
function BottomBar({ bridge, colour }: { bridge: Bridge; colour: string }) {
  const { main, secondary } = bridge
  const mainButton = main && <BarButton key="main" button={main} onPress={bridge.pressMain} background="var(--wa-button)" ink="var(--wa-button-text)" />
  const secondaryButton = secondary && <BarButton key="secondary" button={secondary} onPress={bridge.pressSecondary} background={colour} ink="var(--wa-button)" />
  const position = secondary?.position ?? 'left'
  const first = position === 'left' || position === 'top'

  return (
    <div className="shrink-0 px-3 pt-2 pb-2.5" style={{ background: colour }}>
      <div dir="ltr" className={cn('flex gap-2', (position === 'top' || position === 'bottom') && 'flex-col')}>
        {first ? [secondaryButton, mainButton] : [mainButton, secondaryButton]}
      </div>
    </div>
  )
}

/** One of the bottom bar's buttons: its words or its spinner, its colours, dimmed while inactive, its shine. */
function BarButton({ button, onPress, background, ink }: { button: BottomButton; onPress: () => void; background: string; ink: string }) {
  return (
    <button
      type="button"
      dir="rtl"
      aria-label={button.text}
      aria-disabled={!button.active || button.progress}
      aria-busy={button.progress}
      onClick={onPress}
      className="relative flex h-12 min-w-0 flex-1 items-center justify-center overflow-hidden rounded-xl px-4 text-[15px] font-semibold transition-[opacity,filter] outline-none focus-visible:focus-ring active:brightness-90"
      style={{ background: button.color ?? background, color: button.textColor ?? ink, opacity: button.active ? 1 : 0.5 }}
    >
      {button.progress ? <LoaderCircle className="size-5 animate-spin" aria-hidden /> : <span className="truncate">{button.text}</span>}
      {button.shine && !button.progress && (
        <span
          aria-hidden
          className="pointer-events-none absolute inset-0 animate-[tg-shine_4s_ease-in-out_infinite] bg-[linear-gradient(110deg,transparent_30%,rgb(255_255_255/0.35)_50%,transparent_70%)]"
        />
      )}
    </button>
  )
}

/**
 * A popup or a prompt over the app, in the theme's colours: each button answers with its id. It covers the phone alone,
 * not the page (so no `aria-modal`); answered, the focus goes back where it was as it opened — the frame, for a popup the
 * app opened —, else to the frame.
 */
function PromptView({ prompt, onAnswer, frame }: { prompt: Prompt; onAnswer: (id: string) => void; frame: RefObject<HTMLIFrameElement | null> }) {
  const id = useId()
  // Read as it first draws, before its first button takes the focus.
  const [opener] = useState(() => document.activeElement)

  useEffect(
    () => () => {
      // Its button went with it, and the focus to the page's body; not where the admin took it meanwhile.
      if (document.activeElement !== null && document.activeElement !== document.body) return
      const back = opener instanceof HTMLElement && opener !== document.body && opener.isConnected ? opener : frame.current
      back?.focus()
    },
    [opener, frame],
  )

  return (
    <div className="absolute inset-0 z-40 flex items-center justify-center bg-black/45 p-6">
      <div
        role="alertdialog"
        aria-label={prompt.title || 'پیام مینی‌اپ'}
        aria-describedby={`${id}-message`}
        className="w-full max-w-[19rem] animate-in rounded-2xl p-5 shadow-[0_20px_50px_-20px_rgb(0_0_0/0.6)] ring-1 ring-current/10 zoom-in-95 fade-in"
        style={{ background: 'var(--wa-section)', color: 'var(--wa-text)' }}
      >
        {prompt.kind.startsWith('fingerprint') && <Fingerprint className="mx-auto mb-3 size-11" style={{ color: 'var(--wa-button)' }} aria-hidden />}
        {prompt.title && <h3 className="mb-1.5 text-[16px] font-bold">{prompt.title}</h3>}
        <p id={`${id}-message`} className="text-[14px] leading-7 whitespace-pre-wrap">
          {prompt.message}
        </p>
        <div className="mt-4 flex flex-wrap justify-end gap-1">
          {prompt.buttons.map((button, place) => (
            <button
              key={place}
              type="button"
              // A popup the app opened takes the focus, as Telegram's does.
              autoFocus={place === 0}
              onClick={() => onAnswer(button.id)}
              className="rounded-lg px-3.5 py-2 text-[14px] font-bold transition-colors outline-none hover:bg-current/10 focus-visible:bg-current/10"
              style={{ color: button.destructive ? 'var(--wa-destructive)' : 'var(--wa-button)' }}
            >
              {button.text}
            </button>
          ))}
        </div>
      </div>
    </div>
  )
}
