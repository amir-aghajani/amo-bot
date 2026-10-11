import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { PlanPricesPreviewResponse, PlansResponse } from '@/lib/api-types'
import { PlansPage } from '@/pages/plans'
import { providers, until } from '@/test/render'
import { planRow } from '@/test/rows'
import { json, noContent, refusal, server } from '@/test/server'

/*
 * The plans list (pages/plans): the flag on a plan the bot does not show, the other prices and the term under each
 * plan, no delete for a plan with history, and the bulk price change (components/plans/bulk-prices-modal): preview,
 * apply exactly what was previewed, and a refusal that names the plans.
 */

const NO_SERVER = 'این پلن روی هیچ سروری نیست؛ از فرم پلن یک سرور به آن اضافه کنید.'

/** A plan nothing was ever sold on: it may go. */
const UNUSED = { active_subscriptions: 0, sales: 0, orders: 0, subscriptions: 0 }

function showPlans(plans: PlansResponse['plans']) {
  server().on('GET', '/api/admin/plans', json({ plans } satisfies PlansResponse))
  render(<PlansPage />, { wrapper: providers().wrapper })
}

const openDialog = () => within(document.querySelector<HTMLElement>('dialog[open]') ?? document.body)

async function pressDelete(name: string) {
  fireEvent.pointerDown(await screen.findByRole('button', { name: `عملیات ${name}` }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
  fireEvent.click(await screen.findByRole('menuitem', { name: 'حذف' }))
}

describe('the plans list', () => {
  it('flags a plan the bot does not show, saying why when pressed', async () => {
    showPlans([planRow({ id: 1, name: 'یک‌ماهه', servers: [], unsellable_reason: NO_SERVER }), planRow()])

    expect(await screen.findAllByRole('button', { name: 'در ربات دیده نمی‌شود' })).toHaveLength(1)

    fireEvent.click(screen.getByRole('button', { name: 'در ربات دیده نمی‌شود' }))
    expect(await screen.findByText(NO_SERVER)).toBeTruthy()
  })

  it('says a first purchase’s own price under the plan’s price — «رایگان» for a free one —, and nothing of a plan without one', async () => {
    showPlans([
      planRow({ id: 1, name: 'ماهانه', first_purchase_price: '90000.00' }),
      planRow({ id: 2, name: 'تست', price: '50000.00', first_purchase_price: '0.00' }),
      planRow({ id: 3, name: 'طلایی' }),
    ])

    const discounted = await screen.findByText('خرید اول: ۹۰٬۰۰۰ تومان')
    expect(discounted.closest('td')?.textContent).toBe('۱۲۰٬۰۰۰ تومانخرید اول: ۹۰٬۰۰۰ تومان')
    expect(screen.getByText('خرید اول: رایگان').closest('td')?.textContent).toBe('۵۰٬۰۰۰ تومانخرید اول: رایگان')
    expect(screen.getAllByText(/^خرید اول:/)).toHaveLength(2)
  })

  it('formats each term from its hours: whole days, hours, both, or none', async () => {
    showPlans([
      planRow({ id: 1, name: 'ماهانه' }),
      planRow({ id: 2, name: 'تست', duration_hours: 1 }),
      planRow({ id: 3, name: 'یک روز و نیم', duration_hours: 36 }),
      planRow({ id: 4, name: 'دائمی', duration_hours: 0 }),
    ])

    expect(await screen.findByText('۳۰ روز')).toBeTruthy()
    expect(screen.getByText('۱ ساعت')).toBeTruthy()
    expect(screen.getByText('۱ روز و ۱۲ ساعت')).toBeTruthy()
    expect(screen.getByText('نامحدود')).toBeTruthy()
    // Under the name where the column is not drawn, beside the traffic.
    expect(screen.getByText('۳۰ گیگابایت · ۱ ساعت')).toBeTruthy()
  })

  it('offers no delete for a plan orders or services were made with: it stays, switched off if need be', async () => {
    showPlans([planRow({ counts: { active_subscriptions: 0, sales: 0, orders: 2, subscriptions: 0 } })])

    await pressDelete('طلایی')

    expect(await openDialog().findByText(/با «طلایی» ۲ سفارش ثبت شده و برای حفظ تاریخچه حذف نمی‌شود/)).toBeTruthy()
    expect(openDialog().queryByRole('button', { name: 'حذف' })).toBeNull()
  })

  it('names every kind of history the plan has', async () => {
    showPlans([planRow()])

    await pressDelete('طلایی')

    expect(await openDialog().findByText(/۷ سفارش و ۵ اشتراک ثبت شده/)).toBeTruthy()
  })

  it('asks before deleting a plan nothing was sold on, and deletes it', async () => {
    showPlans([planRow({ counts: UNUSED })])
    server().on('DELETE', '/api/admin/plans/3', noContent)

    await pressDelete('طلایی')
    fireEvent.click(await openDialog().findByRole('button', { name: 'حذف' }))

    await until(() => expect(server().sent('DELETE', '/api/admin/plans/3')).toHaveLength(1))
    await until(() => expect(screen.queryByRole('button', { name: 'طلایی' })).toBeNull())
  })

  it('counts the plans only once the list was read: a failed read knows no number', async () => {
    server().on('GET', '/api/admin/plans', refusal(500, 'خطای سرور'))
    render(<PlansPage />, { wrapper: providers().wrapper })

    expect(await screen.findByRole('button', { name: 'تلاش دوباره' })).toBeTruthy()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('پلن‌ها')
  })

  it('counts the plans it read', async () => {
    showPlans([planRow()])

    await until(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('پلن‌ها۱'))
  })
})

describe('a plan’s agent price', () => {
  it('is shown under the plan’s price («رایگان» for a free one), and not at all for a plan without one', async () => {
    showPlans([
      planRow({ id: 1, name: 'ماهانه', first_purchase_price: '90000.00', agent_price: '100000.00' }),
      planRow({ id: 2, name: 'تست', price: '50000.00', agent_price: '0.00' }),
      planRow({ id: 3, name: 'طلایی' }),
    ])

    const agents = await screen.findByText('نماینده: ۱۰۰٬۰۰۰ تومان')
    expect(agents.closest('td')?.textContent).toBe('۱۲۰٬۰۰۰ تومانخرید اول: ۹۰٬۰۰۰ توماننماینده: ۱۰۰٬۰۰۰ تومان')
    expect(screen.getByText('نماینده: رایگان').closest('td')?.textContent).toBe('۵۰٬۰۰۰ توماننماینده: رایگان')
    expect(screen.getAllByText(/^نماینده:/)).toHaveLength(2)
  })
})

/** Two plans: «ماهانه» 120٬000 with an agent price of 100٬000, «تست» 50٬000. */
const TWO = [planRow({ id: 1, name: 'ماهانه', agent_price: '100000.00' }), planRow({ id: 2, name: 'تست', price: '50000.00', sort: 2 })]

/** What the dialog opened on TWO sends by default, its value typed 10: ten percent more on both plans' prices. */
const TEN_PERCENT = { plan_ids: [1, 2], direction: 'increase', mode: 'percent', value: '10', round_to: 1000, prices: ['price'] }

/** The preview of TEN_PERCENT — «تست» kept from it by `problem` when there is one. */
function previewOf(problem: string | null = null): PlanPricesPreviewResponse {
  return {
    changes: [
      {
        id: 1,
        name: 'ماهانه',
        before: { price: '120000.00', first_purchase_price: null, agent_price: '100000.00' },
        after: { price: '132000.00', first_purchase_price: null, agent_price: '100000.00' },
        problem: null,
      },
      { id: 2, name: 'تست', before: { price: '50000.00', first_purchase_price: null, agent_price: null }, after: { price: '55000.00', first_purchase_price: null, agent_price: null }, problem },
    ],
  }
}

/** The bulk change's dialog, opened from the page's header over TWO. */
async function openRepricing() {
  showPlans(TWO)
  fireEvent.click(await screen.findByRole('button', { name: 'تغییر گروهی قیمت' }))
  return within(await screen.findByRole('dialog', { name: 'تغییر گروهی قیمت' }))
}

describe('changing several plans’ prices at once', () => {
  it('starts on every plan, a raise of the price by a percent rounded to 1٬000 Toman', async () => {
    const dialog = await openRepricing()

    expect(dialog.getByRole('checkbox', { name: 'همه پلن‌ها' }).getAttribute('aria-checked')).toBe('true')
    expect(dialog.getByRole('checkbox', { name: /^ماهانه/ }).getAttribute('aria-checked')).toBe('true')
    expect(dialog.getByRole('checkbox', { name: /^تست/ }).getAttribute('aria-checked')).toBe('true')
    expect(dialog.getByRole('radio', { name: 'افزایش' }).getAttribute('aria-checked')).toBe('true')
    expect(dialog.getByRole('radio', { name: 'درصد' }).getAttribute('aria-checked')).toBe('true')
    expect(dialog.getByRole('combobox', { name: 'گرد کردن به' }).textContent).toBe('۱٬۰۰۰ تومان')
    expect(dialog.getByRole('checkbox', { name: 'قیمت' }).getAttribute('aria-checked')).toBe('true')
    expect(dialog.getByRole('checkbox', { name: 'قیمت نماینده' }).getAttribute('aria-checked')).toBe('false')
    // Nothing previewed, nothing to apply.
    expect(dialog.getByRole('button', { name: 'اعمال تغییر' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('a plan taken out leaves its select-all showing part of them', async () => {
    const dialog = await openRepricing()

    fireEvent.click(dialog.getByRole('checkbox', { name: /^تست/ }))
    expect(dialog.getByRole('checkbox', { name: 'همه پلن‌ها' }).getAttribute('aria-checked')).toBe('mixed')
    fireEvent.click(dialog.getByRole('checkbox', { name: 'همه پلن‌ها' }))
    expect(dialog.getByRole('checkbox', { name: /^تست/ }).getAttribute('aria-checked')).toBe('true')
  })

  it('previews each plan’s prices before and after, a plan that cannot take it saying why — and holds the change', async () => {
    const cannot = 'قیمت نماینده دیگر کمتر از قیمت پلن نمی‌ماند؛ آن را هم تغییر دهید یا خالی کنید.'
    server().on('POST', '/api/admin/plans/prices/preview', json(previewOf(cannot)))
    const dialog = await openRepricing()

    fireEvent.change(dialog.getByLabelText('درصد تغییر'), { target: { value: '10' } })
    fireEvent.click(dialog.getByRole('button', { name: 'پیش‌نمایش' }))

    const table = within(await dialog.findByRole('table'))
    expect(server().sent('POST', '/api/admin/plans/prices/preview')[0]?.body).toEqual(TEN_PERCENT)
    expect(table.getByText('ماهانه').closest('tr')?.textContent).toBe('ماهانه۱۲۰٬۰۰۰ تومان به ۱۳۲٬۰۰۰ تومان')
    expect(table.getByText(cannot).className).toContain('text-danger')
    expect(dialog.getByText('قیمت ۱ پلن تغییر می‌کند؛ ۱ پلن این تغییر را نمی‌پذیرد و تا درست نشود، هیچ قیمتی تغییر نمی‌کند.')).toBeTruthy()
    expect(dialog.getByRole('button', { name: 'اعمال تغییر' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('by a number of Toman, sends it as its digits — its thousands set apart as typed —, on the prices ticked', async () => {
    server().on('POST', '/api/admin/plans/prices/preview', json(previewOf()))
    const dialog = await openRepricing()

    fireEvent.click(dialog.getByRole('radio', { name: 'مبلغ ثابت' }))
    fireEvent.change(dialog.getByLabelText('مبلغ تغییر (تومان)'), { target: { value: '۵٬۰۰۰' } })
    fireEvent.click(dialog.getByRole('checkbox', { name: 'قیمت نماینده' }))
    fireEvent.click(dialog.getByRole('button', { name: 'پیش‌نمایش' }))

    await dialog.findByRole('table')
    expect(server().sent('POST', '/api/admin/plans/prices/preview')[0]?.body).toEqual({ ...TEN_PERCENT, mode: 'amount', value: '5000', prices: ['price', 'agent_price'] })
    expect(dialog.getAllByRole('columnheader').map((head) => head.textContent)).toEqual(['پلن', 'قیمت', 'نماینده'])
  })

  it('drops the preview at any change after it, the change held again', async () => {
    server().on('POST', '/api/admin/plans/prices/preview', json(previewOf()))
    const dialog = await openRepricing()
    fireEvent.change(dialog.getByLabelText('درصد تغییر'), { target: { value: '10' } })
    fireEvent.click(dialog.getByRole('button', { name: 'پیش‌نمایش' }))
    await dialog.findByRole('table')
    expect(dialog.getByRole('button', { name: 'اعمال تغییر' }).getAttribute('aria-disabled')).toBeNull()

    fireEvent.click(dialog.getByRole('radio', { name: 'کاهش' }))

    expect(dialog.queryByRole('table')).toBeNull()
    expect(dialog.getByRole('button', { name: 'اعمال تغییر' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('applied, sends the change previewed and puts the plans it answers with in the list', async () => {
    server()
      .on('POST', '/api/admin/plans/prices/preview', json(previewOf()))
      .on(
        'POST',
        '/api/admin/plans/prices',
        json({ plans: [planRow({ id: 1, name: 'ماهانه', price: '132000.00', agent_price: '100000.00' }), planRow({ id: 2, name: 'تست', price: '55000.00', sort: 2 })] } satisfies PlansResponse),
      )
    const dialog = await openRepricing()
    fireEvent.change(dialog.getByLabelText('درصد تغییر'), { target: { value: '۱۰' } })
    fireEvent.click(dialog.getByRole('button', { name: 'پیش‌نمایش' }))
    await dialog.findByRole('table')

    fireEvent.click(dialog.getByRole('button', { name: 'اعمال تغییر' }))

    await until(() => expect(server().sent('POST', '/api/admin/plans/prices')).toHaveLength(1))
    expect(server().sent('POST', '/api/admin/plans/prices')[0]?.body).toEqual(server().sent('POST', '/api/admin/plans/prices/preview')[0]?.body)
    expect(server().sent('POST', '/api/admin/plans/prices')[0]?.body).toEqual(TEN_PERCENT)
    await until(() => expect(screen.queryByRole('dialog', { name: 'تغییر گروهی قیمت' })).toBeNull())
    const list = within(screen.getByRole('table', { name: 'پلن‌ها' }))
    expect(list.getByText('۱۳۲٬۰۰۰ تومان')).toBeTruthy()
    expect(list.getByText('۵۵٬۰۰۰ تومان')).toBeTruthy()
    expect(server().sent('GET', '/api/admin/plans')).toHaveLength(1)
  })

  it('refused, names under the plans each one that cannot take it — every line —, and asks for a preview again', async () => {
    const first = 'پلن «ماهانه»: قیمت کمتر از صفر می‌شود.'
    const second = 'پلن «تست»: قیمت کمتر از صفر می‌شود.'
    server()
      .on('POST', '/api/admin/plans/prices/preview', json(previewOf()))
      .on('POST', '/api/admin/plans/prices', refusal(422, first, { plan_ids: [first, second] }))
    const dialog = await openRepricing()
    fireEvent.change(dialog.getByLabelText('درصد تغییر'), { target: { value: '10' } })
    fireEvent.click(dialog.getByRole('button', { name: 'پیش‌نمایش' }))
    await dialog.findByRole('table')

    fireEvent.click(dialog.getByRole('button', { name: 'اعمال تغییر' }))

    await until(() => expect(dialog.getByText(second)).toBeTruthy())
    const plans = dialog.getByRole('group', { name: 'پلن‌ها' })
    expect(plans.hasAttribute('data-invalid')).toBe(true)
    expect(within(plans).getByText(first)).toBeTruthy()
    expect(dialog.queryByRole('table')).toBeNull()
    expect(dialog.getByRole('button', { name: 'اعمال تغییر' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('previewed with a value refused, says why under it', async () => {
    const tooMuch = 'درصد تغییر را عددی بیشتر از 0 و حداکثر 100 وارد کنید.'
    server().on('POST', '/api/admin/plans/prices/preview', refusal(422, tooMuch, { value: [tooMuch] }))
    const dialog = await openRepricing()
    fireEvent.click(dialog.getByRole('radio', { name: 'کاهش' }))
    fireEvent.change(dialog.getByLabelText('درصد تغییر'), { target: { value: '150' } })

    fireEvent.click(dialog.getByRole('button', { name: 'پیش‌نمایش' }))

    await until(() => expect(dialog.getByLabelText('درصد تغییر').getAttribute('aria-invalid')).toBe('true'))
    expect(dialog.getByText(tooMuch)).toBeTruthy()
  })
})
