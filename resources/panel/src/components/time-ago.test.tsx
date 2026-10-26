import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { TimeAgo } from '@/components/time-ago'
import { formatDate, timeAgo } from '@/lib/format'

/* When something happened (components/time-ago): how long ago, its date on hover; «—» for a row with no time. */

describe('a time ago', () => {
  it('says how long ago, with the moment for machines and its date on hover', () => {
    const at = '2026-09-17T15:00:00+00:00'
    const time = render(<TimeAgo at={at} />).container.querySelector('time')
    expect(time?.getAttribute('datetime')).toBe(at)
    expect(time?.getAttribute('title')).toBe(formatDate(at))
    expect(time?.textContent).toBe(timeAgo(at))
  })

  it('of a row with no time is a dash, and no time element', () => {
    const shown = render(<TimeAgo at={null} />).container
    expect(shown.textContent).toBe('—')
    expect(shown.querySelector('time')).toBeNull()
  })
})
