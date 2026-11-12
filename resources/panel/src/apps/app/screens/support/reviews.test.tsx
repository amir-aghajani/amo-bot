import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import ReviewsScreen from '@/apps/app/screens/support/reviews'
import * as app from '@/apps/app/test/app'
import { API } from '@/apps/app/test/rows'
import type { StoreReview, StoreReviewsResponse } from '@/lib/api-types'
import { until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * «نظرات» (screens/support/reviews): the approved reviews under their average and count; off — the shop's programs say
 * so, or the reviews answer 404 —, a word that they are; «ثبت نظر» in a sheet — stars, the customer's name to start
 * with, the words, where they connect from — sent signed in, and the word that it shows once approved; refusals under
 * their fields.
 */

const REVIEW: StoreReview = { id: 31, name: 'مریم', rating: 5, body: 'از اول ماه وصلم و مشکلی نداشتم.', context: 'ایرانسل · اندروید · Happ', avatar: 'fox', created_at: '2026-10-06T19:22:41+00:00' }

function page(reviews: StoreReview[], average: number | null): StoreReviewsResponse {
  return { reviews, meta: { page: 1, per_page: 25, total: reviews.length, last_page: 1, summary: { count: reviews.length === 0 ? 0 : 18, average } } }
}

function open(shop = app.shop()) {
  render(app.screen(<ReviewsScreen />, { at: '/reviews', shop }))
}

describe('the reviews', () => {
  it('shows the approved reviews under their average and count', async () => {
    server().on('GET', `${API}/reviews`, json(page([REVIEW, { ...REVIEW, id: 30, name: 'رضا', rating: 4, context: null, body: 'سرعت خوبی دارد.\nپشتیبانی هم خوب است.' }], 4.61)))
    open()

    const card = (await screen.findByText('مریم')).closest('article')
    expect(card).not.toBeNull()
    expect(within(card as HTMLElement).getByText('از اول ماه وصلم و مشکلی نداشتم.')).toBeTruthy()
    expect(within(card as HTMLElement).getByText('ایرانسل · اندروید · Happ')).toBeTruthy()
    expect(within(card as HTMLElement).getByLabelText('۵ از ۵ ستاره')).toBeTruthy()
    expect(screen.getByText('۴٫۶')).toBeTruthy()
    expect(screen.getByText('از ۱۸ نظر')).toBeTruthy()
    expect(screen.getByText(/پشتیبانی هم خوب است/)).toBeTruthy()
  })

  it('says reviews are off when the shop answers 404', async () => {
    const { mainButton } = app.telegram()
    server().on('GET', `${API}/reviews`, refusal(404, 'این وب‌سایت نظرات مشتریان را نشان نمی‌دهد و نظری نمی‌گیرد.'))
    open()

    await screen.findByText('نظرات فعال نیست')
    expect(screen.queryByText(/این وب‌سایت/)).toBeNull()
    expect(mainButton.isVisible).toBe(false)
  })

  it('asks nothing while the shop’s programs say reviews are off here', async () => {
    const shop = app.shop()
    open(app.shop({ shop: { ...shop.shop, programs: { ...shop.shop.programs, reviews: false } } }))

    await screen.findByText('نظرات فعال نیست')
    expect(server().sent('GET', `${API}/reviews`)).toHaveLength(0)
  })

  it('writes one signed in, and says it shows once support approves it', async () => {
    const { mainButton } = app.telegram()
    server().on('GET', `${API}/reviews`, json(page([], null)))
    server().on('POST', `${API}/reviews`, json({ review: { id: 32, status: 'pending' } }, 201))
    open()

    await screen.findByText('هنوز نظری نیست')
    await until(() => expect(mainButton.params.text).toBe('ثبت نظر'))
    mainButton.press()
    const sheet = await screen.findByRole('dialog', { name: 'ثبت نظر' })
    // Telegram's main button is put away under the sheet, which has its own.
    await until(() => expect(mainButton.isVisible).toBe(false))
    expect((within(sheet).getByLabelText('نام') as HTMLInputElement).value).toBe('علی')
    fireEvent.click(within(sheet).getByRole('radio', { name: '۴ ستاره' }))
    fireEvent.change(within(sheet).getByLabelText('نظر شما'), { target: { value: 'سرعت خوبی دارد و قطع نمی‌شود.' } })
    fireEvent.change(within(sheet).getByLabelText(/از کجا وصل می‌شوید/), { target: { value: 'همراه اول · iOS' } })
    fireEvent.click(within(sheet).getByRole('button', { name: 'ثبت نظر' }))

    await screen.findByText('نظر شما ثبت شد و پس از تایید پشتیبانی نمایش داده می‌شود.')
    expect(server().sent('POST', `${API}/reviews`)[0]?.body).toEqual({ name: 'علی', rating: 4, body: 'سرعت خوبی دارد و قطع نمی‌شود.', context: 'همراه اول · iOS' })
    expect(screen.queryByRole('dialog')).toBeNull()
    await until(() => expect(mainButton.params).toMatchObject({ text: 'ثبت نظر', is_visible: true }))
  })

  it('shows the shop’s refusals under their fields', async () => {
    server().on('GET', `${API}/reviews`, json(page([REVIEW], 5)))
    server().on('POST', `${API}/reviews`, refusal(422, 'اطلاعات واردشده معتبر نیست.', { name: ['نام حداقل 2 کاراکتر است.'] }))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'ثبت نظر' }))
    const sheet = await screen.findByRole('dialog')
    fireEvent.click(within(sheet).getByRole('radio', { name: '۵ ستاره' }))
    fireEvent.change(within(sheet).getByLabelText('نظر شما'), { target: { value: 'خیلی خوب بود، ممنون.' } })
    fireEvent.click(within(sheet).getByRole('button', { name: 'ثبت نظر' }))

    await within(sheet).findByText('نام حداقل 2 کاراکتر است.')
    expect(within(sheet).queryByText('اطلاعات واردشده معتبر نیست.')).toBeNull()
  })

  it('forgets a refusal once what it refused is changed', async () => {
    server().on('GET', `${API}/reviews`, json(page([REVIEW], 5)))
    server().on('POST', `${API}/reviews`, refusal(422, 'اطلاعات واردشده معتبر نیست.', { name: ['نام حداقل 2 کاراکتر است.'] }))
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'ثبت نظر' }))
    const sheet = await screen.findByRole('dialog')
    fireEvent.click(within(sheet).getByRole('radio', { name: '۵ ستاره' }))
    fireEvent.change(within(sheet).getByLabelText('نظر شما'), { target: { value: 'خیلی خوب بود، ممنون.' } })
    fireEvent.click(within(sheet).getByRole('button', { name: 'ثبت نظر' }))
    await within(sheet).findByText('نام حداقل 2 کاراکتر است.')

    fireEvent.change(within(sheet).getByLabelText('نام'), { target: { value: 'علی رضایی' } })
    expect(within(sheet).queryByText('نام حداقل 2 کاراکتر است.')).toBeNull()
  })

  it('keeps the reviews when the next page fails, and says why', async () => {
    server().on('GET', `${API}/reviews`, (request) =>
      request.query.get('page') === '2' ? refusal(500, 'خطای سرور') : json({ ...page([REVIEW], 5), meta: { ...page([REVIEW], 5).meta, last_page: 2 } }),
    )
    open()

    fireEvent.click(await screen.findByRole('button', { name: 'نظرات بیشتر' }))

    await screen.findByText(/خطای سرور/)
    expect(screen.getByText('مریم')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'تلاش دوباره' })).toBeNull()
  })
})
