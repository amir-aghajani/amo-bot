import { clsx, type ClassValue } from 'clsx'
import { extendTailwindMerge } from 'tailwind-merge'

/*
 * tailwind-merge has to know the panel's own type scale (index.css @theme): `text-footnote` is a size, not a colour.
 * Unknown `text-*` names count as colours, so without this a size and a colour on one element (`text-body` and
 * `text-primary-foreground` on a button) would cancel each other and the colour would be dropped.
 */
const twMerge = extendTailwindMerge({
  extend: {
    theme: {
      text: ['caption', 'footnote', 'body', 'heading', 'subtitle', 'title', 'stat', 'display'],
    },
  },
})

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * A web address's origin — its scheme, host and port, as the server compares where a kept secret may go
 * (Core\Http\Origin) and Google an OAuth client's JavaScript origins —; the text itself, trimmed, when it is no address.
 */
export function originOf(address: string): string {
  const text = address.trim()
  try {
    return new URL(text).origin
  } catch {
    return text
  }
}

/** Whether two pieces of JSON-shaped data (a form's values, an API answer) hold the same, whatever their identity. */
export function sameData(a: unknown, b: unknown): boolean {
  if (Object.is(a, b)) return true
  if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null || Array.isArray(a) !== Array.isArray(b)) return false
  const keys = Object.keys(a)
  return keys.length === Object.keys(b).length && keys.every((key) => key in b && sameData((a as Record<string, unknown>)[key], (b as Record<string, unknown>)[key]))
}
