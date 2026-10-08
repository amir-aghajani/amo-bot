import { fireEvent, render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { ShopSwitcher } from '@/apps/admin/shop-switcher'
import { LeaveQuestion } from '@/components/leave-question'
import type { Session, ShopRef, ShopsResponse } from '@/lib/api-types'
import { useUnsavedGuard } from '@/lib/use-unsaved-guard'
import { signedIn, until } from '@/test/render'
import { json, refusal, server, silent, type Answer } from '@/test/server'

/*
 * The owner's way between shops (apps/admin/shop-switcher): every shop by its one name — the server's —, a shop whose
 * bot is off (its agency ended) marked so, in the menu and on the picker while it is the one open; opening one loads the
 * panel afresh at that shop's dashboard — after the unsaved-changes question —, the picker held meanwhile; and the shop
 * the tab shows stays on the picker whatever the list of them does: while it is read, when it could not be.
 */

const MAIN: ShopRef = { id: 1, name: 'فروشگاه اصلی', username: null, status: 'active' }
const REZA: ShopRef = { id: 2, name: 'فروشگاه رضا', username: 'reza_shop_bot', status: 'active' }
const HSN: ShopRef = { id: 3, name: 'نماینده: Hsn', username: null, status: 'disabled' }

const session = (shop: ShopRef): Session => ({ name: 'root', shop })

/** A page with a draft nobody saved. */
function Unsaved() {
  useUnsavedGuard(true)
  return null
}

/** The switcher, the owner in `shop`, the shops there are read as `shops` answers (all three by default). */
function open({ shop = MAIN, unsaved = false, shops = json({ shops: [MAIN, REZA, HSN] } satisfies ShopsResponse) }: { shop?: ShopRef; unsaved?: boolean; shops?: Answer } = {}) {
  server().on('GET', '/api/admin/shops', shops)
  render(
    <>
      <LeaveQuestion />
      {unsaved && <Unsaved />}
      <ShopSwitcher />
    </>,
    { wrapper: signedIn({ session: session(shop) }).wrapper },
  )
}

/** The picker's menu, open. */
async function menu(name: string) {
  fireEvent.pointerDown(await screen.findByRole('button', { name }), { button: 0, ctrlKey: false, pointerType: 'mouse' })
  return screen.findByRole('menu')
}

let assign: MockInstance<Location['assign']>

beforeEach(() => {
  assign = vi.spyOn(window.location, 'assign').mockImplementation(() => undefined)
})

describe('the shops', () => {
  it('mark one whose bot is off — and only that one', async () => {
    open()

    const shops = await menu('فروشگاه: فروشگاه اصلی')
    expect(within(within(shops).getByRole('menuitemradio', { name: /نماینده: Hsn/ })).getByText('غیرفعال')).toBeTruthy()
    expect(within(within(shops).getByRole('menuitemradio', { name: /فروشگاه رضا/ })).queryByText('غیرفعال')).toBeNull()
  })

  it('say on the picker that the shop open is off', async () => {
    open({ shop: HSN })

    const picker = await screen.findByRole('button', { name: 'فروشگاه: نماینده: Hsn (غیرفعال)' })
    expect(within(picker).getByText('غیرفعال')).toBeTruthy()
  })

  it('are no picker in the main shop while there is no agent’s to open', async () => {
    open({ shops: json({ shops: [MAIN] } satisfies ShopsResponse) })

    await until(() => expect(server().sent('GET', '/api/admin/shops')).toHaveLength(1))
    expect(screen.queryByRole('button', { name: /^فروشگاه:/ })).toBeNull()
  })
})

describe('the shop the tab shows', () => {
  it('stays on the picker while the shops are read — the way back from an agent’s shop never goes', async () => {
    open({ shop: REZA, shops: silent })

    const list = await menu('فروشگاه: فروشگاه رضا')
    expect(
      within(list)
        .getByRole('menuitemradio', { name: /فروشگاه رضا/ })
        .getAttribute('aria-checked'),
    ).toBe('true')
    expect(within(list).getByText('در حال خواندن فروشگاه‌ها…')).toBeTruthy()
  })

  it('stays on the picker when the shops could not be read, the failure said in its menu with a way to ask again', async () => {
    open({ shop: REZA, shops: refusal(500, 'خطایی در سرور رخ داد. لطفا بعدا دوباره تلاش کنید.') })

    const list = await menu('فروشگاه: فروشگاه رضا')
    expect(await within(list).findByRole('alert')).toBeTruthy()
    server().on('GET', '/api/admin/shops', json({ shops: [MAIN, REZA] } satisfies ShopsResponse))
    fireEvent.click(within(list).getByRole('menuitem', { name: 'تلاش دوباره' }))

    expect(await within(list).findByRole('menuitemradio', { name: /فروشگاه اصلی/ })).toBeTruthy()
    expect(server().sent('GET', '/api/admin/shops')).toHaveLength(2)
  })
})

describe('opening a shop', () => {
  it('loads the panel afresh at its dashboard — its address names it — the picker held meanwhile', async () => {
    open()

    const shops = await menu('فروشگاه: فروشگاه اصلی')
    fireEvent.click(within(shops).getByRole('menuitemradio', { name: /فروشگاه رضا/ }))

    await until(() => expect(assign).toHaveBeenCalledWith('/admin/s/2/'))
    const picker = screen.getByRole('button', { name: 'فروشگاه: فروشگاه اصلی' })
    expect([picker.getAttribute('aria-disabled'), picker.getAttribute('aria-busy')]).toEqual(['true', 'true'])
    expect(server().requests.filter((request) => request.method !== 'GET')).toEqual([])
  })

  it('back to the main shop loads the panel at its own address', async () => {
    open({ shop: REZA })

    const shops = await menu('فروشگاه: فروشگاه رضا')
    fireEvent.click(within(shops).getByRole('menuitemradio', { name: /فروشگاه اصلی/ }))

    await until(() => expect(assign).toHaveBeenCalledWith('/admin/'))
  })

  it('asks first while a draft is unsaved: staying keeps the shop and the draft', async () => {
    open({ unsaved: true })

    fireEvent.click(within(await menu('فروشگاه: فروشگاه اصلی')).getByRole('menuitemradio', { name: /فروشگاه رضا/ }))
    const question = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
    fireEvent.click(within(question).getByRole('button', { name: 'ماندن' }))
    await until(() => expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull())
    expect(assign).not.toHaveBeenCalled()

    fireEvent.click(within(await menu('فروشگاه: فروشگاه اصلی')).getByRole('menuitemradio', { name: /فروشگاه رضا/ }))
    fireEvent.click(within(await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).getByRole('button', { name: 'رها کردن تغییرات' }))

    await until(() => expect(assign).toHaveBeenCalledWith('/admin/s/2/'))
  })
})
