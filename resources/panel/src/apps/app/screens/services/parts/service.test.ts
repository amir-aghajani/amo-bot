import { describe, expect, it } from 'vitest'
import { windowWords } from '@/apps/app/screens/services/parts/service'

/*
 * The shop's words on when a service may be renewed (screens/services/parts/service): its share of traffic written as
 * every other share in the app is («۲۰٪», lib/format's formatPercent).
 */

describe('the renewal window in words', () => {
  it('says nothing while the service may be renewed now', () => {
    expect(windowWords({ open: true, opens_at: null, days_before: null, traffic_percent: null })).toBeNull()
  })

  it('writes the share of traffic left as the app writes every share', () => {
    expect(windowWords({ open: false, opens_at: null, days_before: null, traffic_percent: 20 })).toBe('تمدید این سرویس وقتی ۲۰٪ از حجم یا کمتر مانده باشد ممکن است.')
  })

  it('joins the ways it opens with «یا»', () => {
    expect(windowWords({ open: false, opens_at: null, days_before: 3, traffic_percent: 15 })).toBe('تمدید این سرویس ۳ روز پیش از پایانش یا وقتی ۱۵٪ از حجم یا کمتر مانده باشد ممکن است.')
  })

  it('without a way of its own, after the service ends', () => {
    expect(windowWords({ open: false, opens_at: null, days_before: null, traffic_percent: null })).toBe('تمدید این سرویس بعد از پایانش ممکن است.')
  })
})
