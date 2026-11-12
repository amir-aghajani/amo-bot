import { afterEach, describe, expect, it, vi } from 'vitest'
import { accentPair, contrast, luminance, onAccent, readableOn, rgbOf, setAccent, startTelegram } from '@/apps/app/telegram/theme'
import * as app from '@/apps/app/test/app'

/*
 * The shop's colour on the app (telegram/theme): the text on it by its luminance, and its shade for text and icons that
 * reads on the page — on Telegram's own backgrounds, in either scheme, again when the theme changes.
 */

const LIGHT = ['#efeff4', '#ffffff']
const DARK = ['#0f0f0f', '#1c1c1d']

const root = document.documentElement
const property = (name: string) => root.style.getPropertyValue(name)
const rgb = (color: string) => rgbOf(color)!

afterEach(() => {
  setAccent(null)
  root.classList.remove('dark')
  root.style.removeProperty('color-scheme')
})

describe('the colour maths', () => {
  it('reads #rgb and #rrggbb, and nothing else', () => {
    expect(rgbOf('#ffd60a')).toEqual([255, 214, 10])
    expect(rgbOf('#ABC')).toEqual([170, 187, 204])
    expect(rgbOf(' #2481cc ')).toEqual([36, 129, 204])
    expect(rgbOf('red')).toBeNull()
    expect(rgbOf('#12345')).toBeNull()
    expect(rgbOf('rgb(0 0 0)')).toBeNull()
  })

  it('weighs colours as WCAG does', () => {
    expect(luminance([0, 0, 0])).toBe(0)
    expect(luminance([255, 255, 255])).toBeCloseTo(1, 6)
    expect(luminance(rgb('#2481cc'))).toBeCloseTo(0.2043, 3)
    expect(contrast([0, 0, 0], [255, 255, 255])).toBeCloseTo(21, 6)
    expect(contrast(rgb('#ffffff'), rgb('#2481cc'))).toBeCloseTo(contrast(rgb('#2481cc'), rgb('#ffffff')), 9)
    expect(contrast(rgb('#777777'), rgb('#777777'))).toBe(1)
  })

  it('puts white on a dark or middling accent and black on a light one', () => {
    expect(onAccent('#2481cc')).toBe('#ffffff')
    expect(onAccent('#001f5b')).toBe('#ffffff')
    expect(onAccent('#31a24c')).toBe('#ffffff')
    expect(onAccent('#ffd60a')).toBe('#000000')
    expect(onAccent('#ff9500')).toBe('#000000')
    expect(onAccent('#ffffff')).toBe('#000000')
  })

  it('keeps an accent that already reads on the page as it is', () => {
    expect(readableOn('#1565c0', LIGHT)).toBe('#1565c0')
    expect(readableOn('#ffd60a', DARK)).toBe('#ffd60a')
    expect(readableOn('#64b5f6', DARK)).toBe('#64b5f6')
  })

  it('lightens a dark accent on a dark page just enough to read, keeping its hue', () => {
    const shade = readableOn('#001f5b', DARK)
    const [red, green, blue] = rgb(shade)

    for (const ground of DARK) expect(contrast(rgb(shade), rgb(ground))).toBeGreaterThanOrEqual(4.5)
    expect(luminance(rgb(shade))).toBeGreaterThan(luminance(rgb('#001f5b')))
    expect(blue).toBeGreaterThan(green)
    expect(green).toBeGreaterThan(red)
    // No more than it takes: just over the line on the ground it reads worst on.
    expect(Math.min(...DARK.map((ground) => contrast(rgb(shade), rgb(ground))))).toBeLessThan(4.7)
  })

  it('darkens a light accent on a light page just enough to read', () => {
    const shade = readableOn('#ffd60a', LIGHT)

    for (const ground of LIGHT) expect(contrast(rgb(shade), rgb(ground))).toBeGreaterThanOrEqual(4.5)
    expect(luminance(rgb(shade))).toBeLessThan(luminance(rgb('#ffd60a')))
    expect(Math.min(...LIGHT.map((ground) => contrast(rgb(shade), rgb(ground))))).toBeLessThan(4.7)
  })

  it('reads the direction off the ground, and leaves what it cannot read alone', () => {
    // A dark Telegram theme of its own colours: still lightened.
    const shade = readableOn('#3a0ca3', ['#18222d', '#232e3c'])
    expect(luminance(rgb(shade))).toBeGreaterThan(luminance(rgb('#3a0ca3')))
    expect(contrast(rgb(shade), rgb('#232e3c'))).toBeGreaterThanOrEqual(4.5)

    expect(readableOn('navy', DARK)).toBe('navy')
    expect(readableOn('#001f5b', ['transparent'])).toBe('#001f5b')
  })
})

describe('the shop’s colour on the page', () => {
  it('sets the accent, the text on it and the shade for text from the colour; null takes them all back', () => {
    expect(accentPair()).toBeNull()

    setAccent('#FFD60A')
    expect(property('--app-brand')).toBe('#ffd60a')
    expect(property('--app-brand-on')).toBe('#000000')
    expect(property('--app-brand-text')).toBe(readableOn('#ffd60a', LIGHT))
    expect(accentPair()).toEqual({ color: '#ffd60a', textColor: '#000000' })

    setAccent(null)
    expect(property('--app-brand')).toBe('')
    expect(property('--app-brand-on')).toBe('')
    expect(property('--app-brand-text')).toBe('')
    expect(accentPair()).toBeNull()
  })

  it('takes no colour it cannot read', () => {
    setAccent('#2481cc')
    setAccent('yellow')

    expect(property('--app-brand')).toBe('')
    expect(accentPair()).toBeNull()
  })

  it('reads the shade off Telegram’s own backgrounds, and again when the theme changes', () => {
    const { app: client } = app.telegram({ colorScheme: 'light', themeParams: { bg_color: '#ffffff', secondary_bg_color: '#f0f0f0', section_bg_color: '#ffffff' } })
    startTelegram()
    setAccent('#001f5b')
    expect(property('--app-brand-text')).toBe('#001f5b')
    expect(accentPair()).toEqual({ color: '#001f5b', textColor: '#ffffff' })
    // Telegram's main button in the shop's colours, whichever screen sets its words.
    expect((client.MainButton as app.FakeButton).params).toMatchObject({ color: '#001f5b', text_color: '#ffffff' })

    client.colorScheme = 'dark'
    client.themeParams = { bg_color: '#212121', secondary_bg_color: '#181818', section_bg_color: '#212121' }
    const changed = vi.mocked(client.onEvent).mock.calls.find(([event]) => event === 'themeChanged')?.[1] as () => void
    changed()

    expect(root.classList.contains('dark')).toBe(true)
    expect(property('--app-brand')).toBe('#001f5b')
    expect(property('--app-brand-text')).toBe(readableOn('#001f5b', ['#181818', '#212121']))
    expect(property('--app-brand-text')).not.toBe('#001f5b')
  })

  it('outside Telegram, reads the shade off the scheme’s own backgrounds', () => {
    root.classList.add('dark')
    setAccent('#001f5b')

    expect(property('--app-brand-text')).toBe(readableOn('#001f5b', DARK))
  })
})
