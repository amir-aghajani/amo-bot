import { describe, expect, it } from 'vitest'
import type { AgentBot, Delivery } from '@/lib/api-types'
import { agentBotStatus, EMAILED, isDelivered, NOT_DELIVERED, serverStatus } from '@/lib/statuses'

/**
 * A server's and an agent's bot's state in a word (lib/statuses), in the order the reasons outrank each other; and what
 * came of a message the bot wrote someone of its own accord.
 */

describe('a server', () => {
  const server = { is_active: true, last_error: null, last_checked_at: '2026-10-06T09:00:00Z' }

  it('switched off is that, whatever its panel did', () => {
    expect(serverStatus({ ...server, is_active: false, last_error: 'refused' })).toEqual({ label: 'غیرفعال', tone: 'neutral' })
  })

  it('whose panel failed its last contact is in error', () => {
    expect(serverStatus({ ...server, last_error: 'پنل پاسخ نداد.' })).toEqual({ label: 'خطا در اتصال', tone: 'danger' })
  })

  it('is connected once checked, and unchecked before', () => {
    expect(serverStatus(server)).toEqual({ label: 'متصل', tone: 'success' })
    expect(serverStatus({ ...server, last_checked_at: null })).toEqual({ label: 'بررسی نشده', tone: 'warning' })
  })
})

describe('an agent’s bot', () => {
  const bot: AgentBot = { id: 2, username: 'agent_shop_bot', title: 'Agent', status: 'active', connected: true, problem: null, traffic_balance: 0, connected_at: '2026-10-01T09:00:00Z' }

  it('is off once the agency ended, whatever else holds', () => {
    expect(agentBotStatus({ ...bot, status: 'disabled', connected: false, problem: 'x' })).toEqual({ label: 'غیرفعال', tone: 'neutral' })
  })

  it('waits for its token, then for its problem to be fixed, then runs', () => {
    expect(agentBotStatus({ ...bot, connected: false })).toEqual({ label: 'وصل نشده', tone: 'warning' })
    expect(agentBotStatus({ ...bot, problem: 'تلگرام توکن را نپذیرفت.' })).toEqual({ label: 'مشکل دارد', tone: 'warning' })
    expect(agentBotStatus(bot)).toEqual({ label: 'فعال', tone: 'success' })
  })
})

describe('a message the bot wrote someone of its own accord', () => {
  const deliveries: Delivery[] = ['told', 'emailed', 'turned_away', 'unreachable', 'refused', 'no_telegram']

  it('reached them by Telegram, or — they have none — by email, said so', () => {
    expect(deliveries.filter(isDelivered)).toEqual(['told', 'emailed'])
    expect(`یادآوری ${EMAILED}`).toBe('یادآوری با ایمیل فرستاده شد')
  })

  it('that did not says why, of whom, by the door it was to go through', () => {
    expect(NOT_DELIVERED.unreachable('مشتری', true)).toBe('تلگرام در دسترس نبود')
    expect(NOT_DELIVERED.unreachable('مشتری', false)).toBe('سرور ایمیل در دسترس نبود')
    expect(NOT_DELIVERED.turned_away('نماینده', true)).toBe('نماینده ربات را مسدود کرده یا حسابش دیگر نیست')
    expect(NOT_DELIVERED.no_telegram('مشتری', false)).toMatch(/^این مشتری تلگرام ندارد، و ایمیلی هم برایش نرفت/)
  })
})
