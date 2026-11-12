import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { keys, queryClient } from '@/apps/app/lib/query'
import NotificationsScreen from '@/apps/app/screens/home/notifications'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreAccountResponse, StoreNotification, StoreNotificationsResponse, StoreUnreadResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «اعلان‌ها» (screens/home/notifications): the feed newest first, a page at a time, the unread ones marked; a tap marks
 * one read and opens what it is about (in a panel's preview, only opens it), «خواندن همه» marks every one; the bell's
 * count follows.
 */

const ANSWERED: StoreNotification = {
  id: 306,
  type: 'ticket_answered',
  text: '💬 پاسخ پشتیبانی به تیکت #12\nمشکل سرور برطرف شد.',
  html: '💬 <b>پاسخ پشتیبانی به تیکت #12</b><br>مشکل سرور برطرف شد.',
  subject: { type: 'ticket', id: 12 },
  read: false,
  created_at: new Date(Date.now() - 3_600_000).toISOString(),
}

const REJECTED: StoreNotification = {
  id: 305,
  type: 'payment_rejected',
  text: 'رسید پرداخت سفارش #52 تایید نشد.',
  html: 'رسید پرداخت سفارش #52 تایید نشد.',
  subject: { type: 'order', id: 52 },
  read: true,
  created_at: new Date(Date.now() - 86_400_000).toISOString(),
}

function feed(notifications: StoreNotification[], unread: number, at = 1, last = 1): StoreNotificationsResponse {
  return { notifications, meta: { page: at, per_page: 25, total: notifications.length, last_page: last, unread } }
}

function open() {
  render(app.screen(<NotificationsScreen />, { at: '/notifications', account: app.account({ unread_notifications: 1 }) }))
}

describe('the notifications', () => {
  it('lists the notices newest first with the unread marked, and a tap marks one read and opens what it is about', async () => {
    server()
      .on('GET', `${API}/notifications`, json(feed([ANSWERED, REJECTED], 1)))
      .on('POST', `${API}/notifications/read`, json({ unread: 0 } satisfies StoreUnreadResponse))
    open()

    const rows = await screen.findAllByRole('listitem')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.textContent).toContain('💬 پاسخ پشتیبانی به تیکت #12')
    expect(rows[1]!.textContent).toContain('رسید پرداخت سفارش #52 تایید نشد.')
    expect(within(rows[0]!).getByText('مشکل سرور برطرف شد.')).toBeTruthy()
    expect(within(rows[0]!).getByRole('img', { name: 'خوانده‌نشده' })).toBeTruthy()
    expect(within(rows[1]!).queryByRole('img', { name: 'خوانده‌نشده' })).toBeNull()

    fireEvent.click(within(rows[0]!).getByRole('button'))
    await until(() => expect(server().sent('POST', `${API}/notifications/read`)).toHaveLength(1))
    expect(server().sent('POST', `${API}/notifications/read`)[0]?.body).toEqual({ ids: [306] })
    // The ticket's page opened in its place.
    await until(() => expect(screen.queryByText('اعلان‌ها')).toBeNull())
    await until(() => expect(queryClient.getQueryData<StoreAccountResponse>(keys.me)?.unread_notifications).toBe(0))
  })

  it('in a panel’s preview, opens a notice without marking it read', async () => {
    app.preview()
    server().on('GET', `${API}/notifications`, json(feed([ANSWERED, REJECTED], 1)))
    open()

    const rows = await screen.findAllByRole('listitem')
    fireEvent.click(within(rows[0]!).getByRole('button'))
    await until(() => expect(screen.queryByText('اعلان‌ها')).toBeNull())
    expect(server().sent('POST', `${API}/notifications/read`)).toHaveLength(0)
  })

  it('marks every notice read with «خواندن همه»', async () => {
    server()
      .on('GET', `${API}/notifications`, json(feed([ANSWERED, { ...REJECTED, read: false }], 2)))
      .on('POST', `${API}/notifications/read`, json({ unread: 0 } satisfies StoreUnreadResponse))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'خواندن همه' }))
    await until(() => expect(screen.queryAllByRole('img', { name: 'خوانده‌نشده' })).toHaveLength(0))
    expect(server().sent('POST', `${API}/notifications/read`)[0]?.body).toEqual({})
    expect(screen.queryByRole('button', { name: 'خواندن همه' })).toBeNull()
    expect(queryClient.getQueryData<StoreAccountResponse>(keys.me)?.unread_notifications).toBe(0)
  })

  it('reads the next page when asked', async () => {
    server().on('GET', `${API}/notifications`, (request) => json(request.query.get('page') === '2' ? feed([REJECTED], 0, 2, 2) : feed([{ ...ANSWERED, read: true }], 0, 1, 2)))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'اعلان‌های بیشتر' }))
    expect(await screen.findByText('رسید پرداخت سفارش #52 تایید نشد.')).toBeTruthy()
    expect(screen.getAllByRole('listitem')).toHaveLength(2)
    expect(screen.queryByRole('button', { name: 'اعلان‌های بیشتر' })).toBeNull()
  })

  it('says so when there is none', async () => {
    server().on('GET', `${API}/notifications`, json(feed([], 0)))
    open()

    expect(await screen.findByText('اعلانی ندارید')).toBeTruthy()
  })
})
