/*
 * What the mini app's preview draws of Telegram (components/mini-app/preview): the client's default themes, the two
 * phones it offers and the heights of the parts Telegram draws around the app — the safe areas it tells the app while
 * full screen are read off them.
 */

/** A Telegram theme as the mini app reads it (the Bot API's ThemeParams), every colour #rrggbb. */
export interface ThemeParams {
  bg_color: string
  secondary_bg_color: string
  section_bg_color: string
  text_color: string
  hint_color: string
  link_color: string
  button_color: string
  button_text_color: string
  accent_text_color: string
  section_header_text_color: string
  subtitle_text_color: string
  destructive_text_color: string
  section_separator_color: string
  header_bg_color: string
  bottom_bar_bg_color: string
}

export type TelegramTheme = 'light' | 'dark'

/** Telegram's own light and dark themes, as its Android client hands them to a mini app. */
export const TELEGRAM_THEMES: Record<TelegramTheme, ThemeParams> = {
  light: {
    bg_color: '#ffffff',
    secondary_bg_color: '#efeff4',
    section_bg_color: '#ffffff',
    text_color: '#000000',
    hint_color: '#8e8e93',
    link_color: '#2481cc',
    button_color: '#2481cc',
    button_text_color: '#ffffff',
    accent_text_color: '#2481cc',
    section_header_text_color: '#6d6d72',
    subtitle_text_color: '#8e8e93',
    destructive_text_color: '#e53935',
    section_separator_color: '#c8c7cc',
    header_bg_color: '#efeff4',
    bottom_bar_bg_color: '#ffffff',
  },
  dark: {
    bg_color: '#212121',
    secondary_bg_color: '#0f0f0f',
    section_bg_color: '#212121',
    text_color: '#ffffff',
    hint_color: '#aaaaaa',
    link_color: '#8774e1',
    button_color: '#8774e1',
    button_text_color: '#ffffff',
    accent_text_color: '#8774e1',
    section_header_text_color: '#8774e1',
    subtitle_text_color: '#aaaaaa',
    destructive_text_color: '#ff595a',
    section_separator_color: '#2c2c2c',
    header_bg_color: '#212121',
    bottom_bar_bg_color: '#212121',
  },
}

/** The phones the preview offers: a screen's size in CSS pixels, as a phone's browser lays a page out. */
export const DEVICES = {
  small: { label: 'کوچک', width: 360, height: 740 },
  large: { label: 'بزرگ', width: 412, height: 892 },
} as const

export type Device = keyof typeof DEVICES

/** The phone's status bar: the safe area's top while the app is full screen. */
export const STATUS_BAR = 28

/** Telegram's bar above the app (its close or back, the title, ⋮) while the app is not full screen. */
export const HEADER = 56

/** The phone's gesture bar under everything. */
export const GESTURE_BAR = 16

/** Telegram's floating controls over a full-screen app: the content safe area's top, under the status bar. */
export const CONTROLS = 46

/** The phone's frame around its screen. */
export const BEZEL = 10

/** The Bot API version the preview says it speaks: Telegram 8.0, the one that draws a mini app full screen. */
export const WEB_APP_VERSION = '8.0'

/** What the client says it runs on: a phone, where the app asks for full screen. */
export const WEB_APP_PLATFORM = 'android'

/**
 * Whether a colour is light (#rrggbb, by its luminance): what Telegram draws over it is then dark, else white. Anything
 * else counts as light.
 */
export function isLight(colour: string): boolean {
  const hex = /^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(colour)
  if (!hex) return true
  const [r, g, b] = [hex[1], hex[2], hex[3]].map((part) => Number.parseInt(part ?? '0', 16))

  return 0.299 * (r ?? 0) + 0.587 * (g ?? 0) + 0.114 * (b ?? 0) > 150
}
