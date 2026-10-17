import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

// Read as it is written: the tests' CSS handling hands an import of a stylesheet over empty.
const stylesheet = fs.readFileSync(path.join(import.meta.dirname, 'index.css'), 'utf8')

/*
 * The theme's colours where they must be read (index.css): a refused field's line (--danger-line) at 3:1 at least on
 * every surface it borders and the fills drawn over them, in either theme — and still a red —, and the error words
 * (--danger) at 4.5:1 on those surfaces and on their soft background.
 */

type Rgb = [number, number, number]

/** A theme's custom properties as its block declares them: the light theme's (the first `:root`) or the dark one's. */
function theme(selector: ':root' | '.dark'): Map<string, string> {
  const start = stylesheet.search(new RegExp(`^${selector === '.dark' ? '\\.dark' : ':root'} \\{`, 'm'))
  const block = stylesheet.slice(start, stylesheet.indexOf('}', start))
  return new Map(Array.from(block.matchAll(/--([\w-]+):\s*([^;]+);/g), ([, name = '', value = '']) => [name, value.trim()]))
}

/** A token's colour — a hex, or an alpha over `beneath` (a fill, drawn over the surface beneath it). */
function colour(tokens: Map<string, string>, name: string, beneath?: Rgb): Rgb {
  const value = tokens.get(name) ?? ''
  const hex = /^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(value)
  if (hex) return [Number.parseInt(hex[1] ?? '', 16), Number.parseInt(hex[2] ?? '', 16), Number.parseInt(hex[3] ?? '', 16)]
  const alpha = /^rgb\((\d+) (\d+) (\d+) \/ (\d+)%\)$/.exec(value)
  if (alpha && beneath) {
    const share = Number(alpha[4]) / 100
    const over = (channel: string | undefined, under: number) => Number(channel) * share + under * (1 - share)
    return [over(alpha[1], beneath[0]), over(alpha[2], beneath[1]), over(alpha[3], beneath[2])]
  }
  throw new Error(`--${name} is no colour this test reads: «${value}»`)
}

const linear = (channel: number) => (channel / 255 <= 0.04045 ? channel / 255 / 12.92 : ((channel / 255 + 0.055) / 1.055) ** 2.4)
const luminance = ([r, g, b]: Rgb) => 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b)

/** WCAG's contrast ratio of two colours. */
function contrast(a: Rgb, b: Rgb): number {
  const [lighter, darker] = luminance(a) > luminance(b) ? [luminance(a), luminance(b)] : [luminance(b), luminance(a)]
  return (lighter + 0.05) / (darker + 0.05)
}

const SURFACES = ['surface-0', 'surface-1', 'surface-2', 'surface-3']

/** Every surface of a theme, by name, and each with `fills` drawn over it. */
function grounds(tokens: Map<string, string>, fills: string[]): [string, Rgb][] {
  return SURFACES.flatMap((surface): [string, Rgb][] => {
    const under = colour(tokens, surface)
    return [[surface, under], ...fills.map((fill): [string, Rgb] => [`${fill} over ${surface}`, colour(tokens, fill, under)])]
  })
}

const LIGHT = theme(':root')
const DARK = theme('.dark')

describe('a refused field’s line (--danger-line)', () => {
  it('stands at 3:1 on every light surface, the fills over them and its own soft background', () => {
    const line = colour(LIGHT, 'danger-line')
    for (const [ground, under] of [...grounds(LIGHT, ['fill', 'fill-hover', 'fill-active']), ['danger-soft', colour(LIGHT, 'danger-soft')] satisfies [string, Rgb]]) {
      expect(contrast(line, under), ground).toBeGreaterThanOrEqual(3)
    }
  })

  it('stands at 3:1 on every dark surface and the fill over them', () => {
    const line = colour(DARK, 'danger-line')
    for (const [ground, under] of grounds(DARK, ['fill'])) {
      expect(contrast(line, under), ground).toBeGreaterThanOrEqual(3)
    }
  })

  it('is a red in either theme', () => {
    for (const [r, g, b] of [colour(LIGHT, 'danger-line'), colour(DARK, 'danger-line')]) {
      expect(r - Math.max(g, b)).toBeGreaterThanOrEqual(100)
      expect(g).toBe(b)
    }
  })
})

/** The stylesheet without its `@media (hover: hover)` blocks: what a touch screen, whose taps leave a :hover behind, draws. */
function withoutHoverBlocks(css: string): string {
  let rest = css
  for (let start = rest.indexOf('@media (hover: hover)'); start !== -1; start = rest.indexOf('@media (hover: hover)')) {
    let depth = 0
    let end = rest.indexOf('{', start)
    do {
      if (rest[end] === '{') depth++
      else if (rest[end] === '}') depth--
      end++
    } while (depth > 0 && end < rest.length)
    rest = rest.slice(0, start) + rest.slice(end)
  }
  return rest
}

describe('a table’s sticky last column', () => {
  it('is lit by a hover only where a pointer hovers — a tapped row is not lit in that column alone —, by an open menu and a tick everywhere', () => {
    const touch = withoutHoverBlocks(stylesheet)

    expect(stylesheet).toMatch(/\[data-slot='table-row'\]:hover > \[data-slot='table-cell'\]:last-child/)
    expect(touch).not.toMatch(/\[data-slot='table-row'\][^{}]*:hover/)
    expect(touch).toMatch(/\[data-slot='table-row'\]:has\(\[aria-expanded='true'\]\) > \[data-slot='table-cell'\]:last-child \{/)
    expect(touch).toMatch(/\[data-slot='table-row'\]\[data-state='selected'\] > \[data-slot='table-cell'\]:last-child \{/)
  })
})

describe('the error words (--danger)', () => {
  it('read at 4.5:1 on every surface and on their soft background, in either theme', () => {
    for (const tokens of [LIGHT, DARK]) {
      const words = colour(tokens, 'danger')
      for (const [ground, under] of [...grounds(tokens, []), ['danger-soft', colour(tokens, 'danger-soft')] satisfies [string, Rgb]]) {
        expect(contrast(words, under), ground).toBeGreaterThanOrEqual(4.5)
      }
    }
  })
})
