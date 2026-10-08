import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { statusTabs } from '@/components/status-badge'
import { ORDER_STATUS } from '@/lib/statuses'

/** A status queue's tabs (components/status-badge): «همه» first, then each status in its vocabulary's words, a count beside the ones that wait. */

describe('a status queue’s tabs', () => {
  it('start with «همه», then the statuses in the order asked, in their own words', () => {
    const tabs = statusTabs(ORDER_STATUS, ['pending', 'failed', 'fulfilled'])

    expect(tabs.map((tab) => tab.value)).toEqual(['', 'pending', 'failed', 'fulfilled'])
    expect(tabs.map((tab) => tab.label)).toEqual(['همه', 'در انتظار پرداخت', 'ناموفق', 'تکمیل‌شده'])
  })

  it('carry a count beside a status that waits on someone, in Persian digits — none at zero', () => {
    const [, pending, failed] = statusTabs(ORDER_STATUS, ['pending', 'failed'], { pending: 0, failed: 12 })

    expect(pending?.label).toBe('در انتظار پرداخت')
    expect(render(<>{failed?.label}</>).container.textContent).toBe('ناموفق۱۲')
  })
})
