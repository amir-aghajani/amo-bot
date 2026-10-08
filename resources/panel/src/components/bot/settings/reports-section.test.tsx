import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { ReportsSection } from '@/components/bot/settings/reports-section'
import type { ReportGroupData, ReportGroupResponse, ReportGroupTestResponse, Session } from '@/lib/api-types'
import { AGENT_SHOP, OWNER, signedIn, until } from '@/test/render'
import { botSettingsRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * The «گروه گزارش‌ها» section of the bot settings (components/bot/settings/reports-section): the topics' switches are
 * labelled by the group's read — which, failing, the switches' card says too, with a way to ask again; never a skeleton
 * that pulses for ever. The group's card says what the group gets — the agency's requests in the main bot's shop alone
 * —, where a bot not known yet gets its name — an agent's with its token, never the owner's settings —, sends a «پیام
 * تست», and a refused action is told once, by the panel's toast, in the server's words.
 */

const SETTINGS = botSettingsRow()

const GROUP: ReportGroupData = {
  connected: true,
  chat_id: -1001234567890,
  title: 'گزارش‌های فروشگاه',
  connected_at: '2026-10-01T10:00:00Z',
  problem: null,
  paused_until: null,
  waiting: 0,
  topics: [],
  link: null,
  attempt: null,
  bot_username: 'amo_bot',
}

function showSection(session: Session = OWNER) {
  render(<ReportsSection settings={SETTINGS} />, { wrapper: signedIn({ session }).wrapper })
}

describe('the report topics', () => {
  it('say their read failed, with a way to ask again', async () => {
    server().on('GET', '/api/admin/bot/report-group', refusal(500, 'خطای سرور.'))
    showSection()

    expect(await screen.findByText('بخش‌های گزارش بارگذاری نشد.')).toBeTruthy()
    expect(screen.getAllByRole('button', { name: 'تلاش دوباره' }).length).toBeGreaterThan(0)
    await until(() => expect(document.querySelectorAll('[data-slot="skeleton"]')).toHaveLength(0))
  })
})

describe('the report group', () => {
  it('gets the agency’s requests in the main bot’s shop alone', async () => {
    server().on('GET', '/api/admin/bot/report-group', json({ group: GROUP } satisfies ReportGroupResponse))
    showSection()
    expect(await screen.findByText(/خطاها، تیکت‌ها، نظرات و درخواست‌های نمایندگی در یک گروه تلگرام/)).toBeTruthy()
  })

  it('in an agent’s shop says nothing of the agency', async () => {
    server().on('GET', '/api/admin/bot/report-group', json({ group: GROUP } satisfies ReportGroupResponse))
    showSection(AGENT_SHOP)
    expect(await screen.findByText(/کاربران جدید، خطاها، تیکت‌ها و نظرات در یک گروه تلگرام/)).toBeTruthy()
    expect(screen.queryByText(/درخواست‌های نمایندگی/)).toBeNull()
  })

  it('sends a test message to every topic, said in a toast', async () => {
    vi.spyOn(toast, 'success')
    server()
      .on('GET', '/api/admin/bot/report-group', json({ group: GROUP } satisfies ReportGroupResponse))
      .on('POST', '/api/admin/bot/report-group/test', json({ group: GROUP, queued: 6, sent: 6 } satisfies ReportGroupTestResponse))
    showSection()

    fireEvent.click(await screen.findByRole('button', { name: 'پیام تست' }))

    await until(() => expect(toast.success).toHaveBeenCalledWith('پیام تست به ۶ تاپیک فرستاده شد'))
  })

  it('whose bot is not known yet says where its name comes from — for the shop on screen', async () => {
    const unknown = { ...GROUP, connected: false, chat_id: null, title: null, connected_at: null, bot_username: null }
    server().on('GET', '/api/admin/bot/report-group', json({ group: unknown } satisfies ReportGroupResponse))
    showSection(AGENT_SHOP)

    expect(await screen.findByText(/ربات این فروشگاه هنوز وصل نشده است: توکنش در ربات اصلی از «نمایندگی» ← «ربات من» فرستاده می‌شود/)).toBeTruthy()
    expect(screen.queryByText(/بررسی توکن/)).toBeNull()
  })

  it('tells a refusal once, in the server’s words', async () => {
    vi.spyOn(toast, 'error')
    const refused = 'نام کاربری ربات هنوز مشخص نیست؛ ربات را یک بار اجرا کنید.'
    server()
      .on('GET', '/api/admin/bot/report-group', json({ group: { ...GROUP, connected: false, chat_id: null, title: null, connected_at: null } } satisfies ReportGroupResponse))
      .on('POST', '/api/admin/bot/report-group/link', refusal(422, refused, { link: [refused] }))
    showSection()

    fireEvent.click(await screen.findByRole('button', { name: 'ساخت لینک اتصال' }))

    await until(() => expect(toast.error).toHaveBeenCalledWith(refused, { description: undefined }))
    expect(toast.error).toHaveBeenCalledTimes(1)
  })
})
