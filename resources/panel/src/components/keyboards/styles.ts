import type { ButtonStyle } from '@/lib/api-types'

/**
 * Telegram's button styles as the editor and the preview show them. The swatches are the `--tg-*`
 * tokens of index.css (what the official clients draw for `style`); "default" is whatever the
 * client uses for plain buttons. Which styles there are is the API's (`KeyboardsResponse.styles`).
 */
export interface StyleOption {
  value: ButtonStyle | null
  title: string
  /** The colour in words, for the picker card ("آبی"). */
  colorName: string
  /** Swatch / preview colours. */
  swatch: string
  text: string
}

const DEFAULT: StyleOption = { value: null, title: 'پیش‌فرض', colorName: 'رنگ خودکار', swatch: 'var(--tg-button)', text: 'var(--tg-button-text)' }

const STYLES: Record<ButtonStyle, StyleOption> = {
  primary: { value: 'primary', title: 'اصلی', colorName: 'آبی', swatch: 'var(--tg-primary)', text: '#ffffff' },
  success: { value: 'success', title: 'موفقیت', colorName: 'سبز', swatch: 'var(--tg-success)', text: '#ffffff' },
  danger: { value: 'danger', title: 'خطر', colorName: 'قرمز', swatch: 'var(--tg-danger)', text: '#ffffff' },
}

export function styleOption(style: ButtonStyle | null): StyleOption {
  return style === null ? DEFAULT : STYLES[style]
}

/** The picker's choices: the client's default, then each style the API offers. */
export function styleOptions(styles: readonly ButtonStyle[]): StyleOption[] {
  return [DEFAULT, ...styles.map((style) => STYLES[style])]
}
