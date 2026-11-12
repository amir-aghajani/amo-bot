import { onPhone, telegram, when, type ThemeParams } from '@/apps/app/telegram/webapp'

/*
 * The app in Telegram's frame: it says it is ready, takes the whole height — full screen on a phone when the shop wants
 * it (Bot API 8.0), with the swipe that would close it locked (7.7) so a scroll never shuts it —, and follows the user's
 * Telegram theme: its colours are the --tg-theme-* properties telegram-web-app.js keeps on the page (app.css reads them),
 * the light or dark scheme a class on <html>, and Telegram's own header, background and bottom bar painted to match
 * (6.1, 7.10), again whenever the theme changes. The shop's own colour, when it set one, is the app's accent: the text on
 * it is white or black by the colour's luminance, and where the accent is itself text or an icon it is a shade of it that
 * reads on the page — lightened toward white on a dark theme, darkened toward black on a light one, only as far as it must.
 */

/** Hand the page over to Telegram: ready, expanded, full screen and locked as asked, and its colours followed. */
export function startTelegram(): void {
  const app = telegram()
  paintScheme(app?.colorScheme ?? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'))
  if (app === null) return

  app.ready()
  app.expand()
  when('7.7', (it) => it.disableVerticalSwipes?.())
  paintFrame()
  app.onEvent('themeChanged', () => {
    paintScheme(app.colorScheme)
    paintFrame()
    paintAccent()
  })
}

/** Full screen on a phone (Bot API 8.0): the app draws under Telegram's header, keeping clear of its controls. */
export function goFullscreen(): void {
  if (!onPhone()) return
  when('8.0', (app) => {
    if (!app.isFullscreen) app.requestFullscreen?.()
  })
}

/** The shop's colour, #rrggbb, while it set one; null for Telegram's button colour. */
let brand: string | null = null

/** The shop's colour as the app's accent (#rrggbb); null — or a colour that is no #rgb or #rrggbb — keeps Telegram's. */
export function setAccent(color: string | null): void {
  const rgb = color === null ? null : rgbOf(color)
  brand = rgb === null ? null : hexOf(rgb)
  paintAccent()
  // Telegram's main button in the shop's colours too, whichever screen holds it: its script keeps what a screen's
  // setParams does not give.
  const pair = accentPair()
  if (pair !== null) when('6.0', (app) => app.MainButton.setParams({ color: pair.color, text_color: pair.textColor }))
}

/** The shop's accent and the text on it, #rrggbb (Telegram's main button in its colours); null while it set none. */
export function accentPair(): { color: string; textColor: string } | null {
  return brand === null ? null : { color: brand, textColor: onAccent(brand) }
}

/** The accent on the page (app.css reads them): the shop's colour, the text on it, and its shade for text and icons. */
function paintAccent(): void {
  const style = document.documentElement.style
  if (brand === null) {
    for (const property of ['--app-brand', '--app-brand-on', '--app-brand-text']) style.removeProperty(property)
    return
  }
  style.setProperty('--app-brand', brand)
  style.setProperty('--app-brand-on', onAccent(brand))
  style.setProperty('--app-brand-text', readableOn(brand, backgrounds()))
}

/** app.css's fallbacks for a client (or a browser) that sends no background: the page's and a section's. */
const GROUNDS = { light: ['#efeff4', '#ffffff'], dark: ['#0f0f0f', '#1c1c1d'] } as const

/** What the accent is read on, as app.css paints it: the page (Telegram's secondary background) and a section. */
function backgrounds(): string[] {
  const theme: ThemeParams = telegram()?.themeParams ?? {}
  const [page, section] = GROUNDS[document.documentElement.classList.contains('dark') ? 'dark' : 'light']

  return [given(theme.secondary_bg_color, page), given(theme.section_bg_color ?? theme.bg_color, section)]
}

/** Telegram's colour while it is one the app reads, else app.css's own. */
function given(color: string | undefined, fallback: string): string {
  return color !== undefined && rgbOf(color) !== null ? color : fallback
}

type Rgb = readonly [number, number, number]

const WHITE: Rgb = [255, 255, 255]
const BLACK: Rgb = [0, 0, 0]

/** `#rgb` or `#rrggbb` as its channels, 0 to 255; null for anything else. */
export function rgbOf(color: string): Rgb | null {
  const digits = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(color.trim())?.[1]
  if (digits === undefined) return null
  const full = digits.length === 3 ? [...digits].map((digit) => digit + digit).join('') : digits
  const channel = (at: number) => Number.parseInt(full.slice(at, at + 2), 16)

  return [channel(0), channel(2), channel(4)]
}

function hexOf(rgb: Rgb): string {
  return `#${rgb.map((channel) => Math.round(channel).toString(16).padStart(2, '0')).join('')}`
}

/** A channel (0 to 255) as light, without sRGB's curve. */
function linear(channel: number): number {
  const value = channel / 255
  return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
}

/** WCAG's relative luminance: 0 for black, 1 for white. */
export function luminance([red, green, blue]: Rgb): number {
  return 0.2126 * linear(red) + 0.7152 * linear(green) + 0.0722 * linear(blue)
}

/** WCAG's contrast ratio of two colours: 1 (the same) to 21 (black on white). */
export function contrast(one: Rgb, other: Rgb): number {
  const [a, b] = [luminance(one), luminance(other)]

  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
}

/** The text on a filled accent: white while it holds 3:1 there (as on Telegram's own buttons), black on a light colour. */
export function onAccent(color: string): string {
  const rgb = rgbOf(color)

  return rgb === null || contrast(rgb, WHITE) >= 3 ? '#ffffff' : '#000000'
}

/**
 * The accent as text or an icon on `grounds` (#rrggbb): the colour itself while it stands out `target`:1 on each (4.5,
 * WCAG's for text), else mixed toward white on dark grounds — toward black on light ones — just as far as it must.
 */
export function readableOn(color: string, grounds: readonly string[], target = 4.5): string {
  const rgb = rgbOf(color)
  const under = grounds.map(rgbOf).filter((ground): ground is Rgb => ground !== null)
  const [first] = under
  if (rgb === null || first === undefined) return color
  const reads = (candidate: Rgb) => under.every((ground) => contrast(candidate, ground) >= target)
  if (reads(rgb)) return hexOf(rgb)

  // Toward the end that stands out on the ground. Once a share of it reads, every larger share does: the least one that
  // reads is found by halving the range.
  const toward = contrast(first, WHITE) >= contrast(first, BLACK) ? WHITE : BLACK
  const mixed = (share: number): Rgb => {
    const channel = (from: number, to: number) => Math.round(from + (to - from) * share)
    return [channel(rgb[0], toward[0]), channel(rgb[1], toward[1]), channel(rgb[2], toward[2])]
  }
  let [low, high] = [0, 1]
  for (let step = 0; step < 16; step++) {
    const middle = (low + high) / 2
    if (reads(mixed(middle))) high = middle
    else low = middle
  }

  return hexOf(mixed(high))
}

/** The scheme on <html> (`dark` class, `color-scheme`), for what app.css cannot read off Telegram's colours. */
function paintScheme(scheme: 'light' | 'dark'): void {
  const root = document.documentElement
  root.classList.toggle('dark', scheme === 'dark')
  root.style.colorScheme = scheme
}

/** Telegram's header, the page's background and its bottom bar in the app's own colours. */
function paintFrame(): void {
  const app = telegram()
  if (app === null) return
  const theme: ThemeParams = app.themeParams
  const page = theme.secondary_bg_color ?? theme.bg_color
  if (page === undefined) return

  when('6.1', (it) => {
    it.setHeaderColor('secondary_bg_color')
    it.setBackgroundColor(page)
  })
  when('7.10', (it) => it.setBottomBarColor?.(theme.section_bg_color ?? theme.bg_color ?? page))
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', page)
}
