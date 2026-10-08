import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { Creating } from '@/components/form-footer'
import { PlanForm } from '@/components/plans/plan-form'
import type { PlanOptions } from '@/lib/api-types'
import { signedIn, until } from '@/test/render'
import { planRow } from '@/test/rows'
import { json, refusal, server } from '@/test/server'

/*
 * A plan's form (components/plans/plan-form, components/plans/server-entries): its three sections laid out in one place,
 * so the dialog keeps its height whichever is shown; every refusal about its servers listed, not the first alone, and
 * the servers counted among the fields to fix; a servers section that says why there is nothing to pick — the servers
 * not read (with a way to ask again), or none there: the owner is sent to add one, an agent is told whom to ask —, whose
 * «افزودن به پلن» keeps the focus it was pressed with, and which sends the owner to a server's page and an agent, who has
 * none, to support. The category's hint says the bot's rule: the step comes as soon as one category is active.
 */

const OPTIONS: PlanOptions = {
  categories: [],
  servers: [
    { id: 1, name: 'آلمان', is_active: true, serves_subscriptions: true, driver_label: '3X-UI', inbounds: [], unsellable_reason: null },
    { id: 2, name: 'هلند', is_active: false, serves_subscriptions: true, driver_label: '3X-UI', inbounds: [], unsellable_reason: 'سرور غیرفعال است.' },
  ],
}

/** A new plan's form, as its dialog opens it (EditorModal without a row: Creating). */
function showForm(options: PlanOptions | 'failing' = OPTIONS, addServers?: string) {
  server().on('GET', '/api/admin/plans/options', options === 'failing' ? refusal(500, 'خطای سرور.') : json(options))
  render(
    <Creating value>
      <PlanForm plan={null} addServers={addServers} onSaved={vi.fn()} onCancel={vi.fn()} />
    </Creating>,
    { wrapper: signedIn().wrapper },
  )
}

const panels = () => [...document.querySelectorAll('[role="tabpanel"]')]

async function openServers() {
  fireEvent.click(await screen.findByRole('tab', { name: /سرورها/ }))
}

describe('a plan’s form', () => {
  it('lays every section out in one place — those not shown out of sight and out of reach —, so its height never jumps', async () => {
    showForm()
    await screen.findByRole('tab', { name: /مشخصات/ })

    expect(panels()).toHaveLength(3)
    expect(panels().some((panel) => panel.hasAttribute('hidden'))).toBe(false)
    expect(panels().map((panel) => panel.hasAttribute('inert'))).toEqual([false, true, true])

    await openServers()
    expect(panels().map((panel) => panel.hasAttribute('inert'))).toEqual([true, true, false])
    expect(panels().map((panel) => panel.classList.contains('invisible'))).toEqual([true, true, false])
  })

  it('lists every refusal about its servers, on the section that holds them', async () => {
    const twice = '«آلمان» بیش از یک بار اضافه شده است؛ اینباندهایش را در یک ردیف جمع کنید.'
    const disabled = 'اینباند «Old» روی «هلند» غیرفعال است و قابل فروش نیست.'
    server().on('POST', '/api/admin/plans', refusal(422, twice, { servers: [twice, disabled] }))
    showForm()

    fireEvent.click(await screen.findByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(screen.getByText(disabled)).toBeTruthy())
    expect(screen.getByText(twice)).toBeTruthy()
    expect(screen.getByRole('tab', { name: /سرورها/ }).getAttribute('aria-selected')).toBe('true')
    // The servers are one field to fix, and the first one: the focus is on them.
    expect(screen.getByText('یک فیلد نیاز به اصلاح دارد')).toBeTruthy()
    expect(document.activeElement).toBe(screen.getByRole('group', { name: 'سرورها' }))
  })

  it('counts its servers among the fields to fix, with the others', async () => {
    const none = 'پلن باید دست‌کم روی یک سرور فروخته شود.'
    server().on('POST', '/api/admin/plans', refusal(422, 'Refused.', { name: ['نام پلن را وارد کنید.'], price: ['قیمت را وارد کنید.'], servers: [none] }))
    showForm()

    fireEvent.click(await screen.findByRole('button', { name: 'افزودن پلن' }))

    await until(() => expect(screen.getByText('۳ فیلد نیاز به اصلاح دارد')).toBeTruthy())
    expect(screen.getByRole('group', { name: 'سرورها' }).getAttribute('aria-describedby')).toBeTruthy()
  })

  it('says the bot asks for a category first as soon as one is active', async () => {
    showForm({ ...OPTIONS, categories: [{ id: 1, name: 'ماهانه', is_active: true }] })

    expect(await screen.findByText(/تا وقتی دسته فعالی هست، مشتری در ربات اول دسته را انتخاب می‌کند/)).toBeTruthy()
  })
})

/** A server picked in the servers section's adder — its list closed, the focus back on its field. */
async function pick(name: string) {
  const field = await screen.findByRole('combobox', { name: 'سرور' })
  fireEvent.keyDown(field, { key: 'Enter' })
  fireEvent.click(await screen.findByRole('option', { name: new RegExp(name) }))
  await until(() => expect(document.activeElement).toBe(field))
}

describe('adding a server', () => {
  it('holds «افزودن به پلن» while there is nothing to add — never disabled —, so the button just pressed keeps the focus', async () => {
    showForm()
    await openServers()
    await pick('آلمان')
    const add = screen.getByRole('button', { name: 'افزودن به پلن' })

    add.focus()
    fireEvent.click(add)

    expect(await screen.findByText(/کل سرور ·/)).toBeTruthy()
    expect([add.hasAttribute('disabled'), add.getAttribute('aria-disabled')]).toEqual([false, 'true'])
    expect(document.activeElement).toBe(add)
  })

  it('that takes the last server leaves the focus on the word that every one is on the plan', async () => {
    showForm({ ...OPTIONS, servers: OPTIONS.servers.slice(0, 1) })
    await openServers()
    await pick('آلمان')
    const add = screen.getByRole('button', { name: 'افزودن به پلن' })

    add.focus()
    fireEvent.click(add)

    const word = await screen.findByText('همه سرورها به این پلن اضافه شده‌اند.')
    expect(document.activeElement).toBe(word)
  })
})

describe('a server’s inbounds', () => {
  const plan = planRow({ servers: [{ server: { id: 1, name: 'آلمان', is_active: true, serves_subscriptions: true }, all_inbounds: true, inbounds: [], unsellable_reason: null }] })

  it('are marked for sale on the server’s page, in the owner’s panel', async () => {
    server().on('GET', '/api/admin/plans/options', json(OPTIONS))
    render(<PlanForm plan={plan} addServers="/servers" onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: signedIn().wrapper })
    await openServers()

    expect(await screen.findByText(/در صفحه سرور اینباندها را برای فروش علامت بزنید/)).toBeTruthy()
  })

  it('are support’s to mark for sale in an agent’s panel, which has no server’s page', async () => {
    server().on('GET', '/api/admin/plans/options', json(OPTIONS))
    render(<PlanForm plan={plan} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: signedIn().wrapper })
    await openServers()

    expect(await screen.findByText(/از پشتیبانی بخواهید اینباندهایش را برای فروش علامت بزند/)).toBeTruthy()
    expect(screen.queryByText(/صفحه سرور/)).toBeNull()
  })
})

describe('its servers section', () => {
  it('says the servers were not read, with a way to ask again', async () => {
    showForm('failing')
    await openServers()

    expect(await screen.findByText('سرورها بارگذاری نشد.')).toBeTruthy()

    server().on('GET', '/api/admin/plans/options', json(OPTIONS))
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    expect(await screen.findByRole('combobox', { name: 'سرور' })).toBeTruthy()
  })

  it('sends the owner to add a server while there is none', async () => {
    showForm({ categories: [], servers: [] }, '/servers')
    await openServers()

    const link = await screen.findByRole('link', { name: 'اول یک سرور اضافه کنید' })
    expect(link.getAttribute('href')).toBe('/servers')
  })

  it('tells an agent whom to ask, their panel adding no server', async () => {
    showForm({ categories: [], servers: [] })
    await openServers()

    expect(await screen.findByText(/از پشتیبانی بخواهید سروری اضافه کند/)).toBeTruthy()
    expect(screen.queryByRole('link')).toBeNull()
  })

  it('says why an entry’s server cannot sell, in one sentence', async () => {
    server().on('GET', '/api/admin/plans/options', json(OPTIONS))
    const plan = planRow({ servers: [{ server: { id: 2, name: 'هلند', is_active: false, serves_subscriptions: true }, all_inbounds: true, inbounds: [], unsellable_reason: 'سرور غیرفعال است.' }] })
    render(<PlanForm plan={plan} onSaved={vi.fn()} onCancel={vi.fn()} />, { wrapper: signedIn().wrapper })
    await openServers()

    expect(await screen.findByText('سرور غیرفعال است. تا درست نشود، مشتری این سرور را نمی‌بیند.')).toBeTruthy()
  })
})
