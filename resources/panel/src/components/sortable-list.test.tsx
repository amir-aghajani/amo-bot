import { useState } from 'react'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { toast } from 'sonner'
import { describe, expect, it, vi } from 'vitest'
import { agencyLevelsQuery } from '@/components/agency/queries'
import { OrderCell, RemoveConfirm } from '@/components/sortable-list'
import { Table, TableBody, TableCell, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import type { AgencyLevelRow } from '@/lib/api-types'
import { planCategoriesQuery } from '@/lib/queries'
import { useRows } from '@/lib/use-rows'
import { providers, until } from '@/test/render'
import { categoryRow, levelRow } from '@/test/rows'
import { json, noContent, refusal, server } from '@/test/server'

/*
 * An admin-ordered list's ▲▼ (components/sortable-list, components/order-controls): each press is one step and one
 * request; while one runs every arrow holds — keeping the focus it has, taking no press — and the arrow pressed keeps the
 * focus as its row moves, so the keyboard presses on: the other arrow of the row once it reached an end. A row's delete
 * (RemoveConfirm) says a refusal in the dialog that asked, and offers none to a row that already says it cannot go.
 */

const CATEGORIES = [categoryRow({ id: 1, name: 'ماهانه', sort: 1 }), categoryRow({ id: 2, name: 'سه‌ماهه', sort: 2 }), categoryRow({ id: 3, name: 'سالانه', sort: 3 })]

function Categories() {
  const list = useRows({
    query: planCategoriesQuery,
    list: 'categories',
    reorder: (ids) => api.post('/plans/categories/reorder', { ids }),
    remove: (category) => api.delete(`/plans/categories/${category.id}`),
  })

  return (
    <Table>
      <TableBody>
        {list.rows.map((category, index) => (
          <TableRow key={category.id}>
            <OrderCell list={list} index={index} label={category.name} />
            <TableCell>{category.name}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}

async function showCategories() {
  server().on('GET', '/api/admin/plans/categories', json({ categories: CATEGORIES }))
  render(<Categories />, { wrapper: providers().wrapper })
  await screen.findByText('سالانه')
}

const arrow = (name: string) => screen.getByRole('button', { name })
const order = () => screen.getAllByRole('row').map((row) => row.textContent)

describe('a row’s ▲▼', () => {
  it('keeps the focus on the arrow pressed as its row moves — the other arrow once the row reached the end', async () => {
    await showCategories()
    const saved = server().hold('POST', '/api/admin/plans/categories/reorder')

    const down = arrow('انتقال سه‌ماهه به پایین')
    down.focus()
    fireEvent.click(down)

    await until(() => expect(order()).toEqual(['ماهانه', 'سالانه', 'سه‌ماهه']))
    expect(document.activeElement).toBe(arrow('انتقال سه‌ماهه به بالا'))
    expect(arrow('انتقال سه‌ماهه به پایین')).toHaveProperty('disabled', true)

    saved.answer(json({ categories: [CATEGORIES[0], CATEGORIES[2], CATEGORIES[1]] }))
    await until(() => expect(saved.waiting).toBe(0))
  })

  it('holds every arrow while a move runs: a press then sends nothing, and the focus stays', async () => {
    await showCategories()
    const saved = server().hold('POST', '/api/admin/plans/categories/reorder')

    const up = arrow('انتقال سالانه به بالا')
    up.focus()
    fireEvent.click(up)
    await until(() => expect(saved.waiting).toBe(1))
    expect(document.activeElement).toBe(arrow('انتقال سالانه به بالا'))

    for (const button of screen.getAllByRole('button')) {
      if (!(button as HTMLButtonElement).disabled) expect(button.getAttribute('aria-disabled')).toBe('true')
    }
    fireEvent.click(arrow('انتقال سالانه به بالا'))
    fireEvent.click(arrow('انتقال ماهانه به پایین'))
    expect(server().sent('POST', '/api/admin/plans/categories/reorder')).toHaveLength(1)
    expect(server().sent('POST', '/api/admin/plans/categories/reorder')[0]?.body).toEqual({ ids: [1, 3, 2] })

    saved.answer(json({ categories: [CATEGORIES[0], CATEGORIES[2], CATEGORIES[1]] }))
    await until(() => expect(arrow('انتقال سالانه به بالا').getAttribute('aria-disabled')).toBeNull())
    expect(document.activeElement).toBe(arrow('انتقال سالانه به بالا'))
  })
})

const LEVELS = [levelRow({ id: 1, name: 'طلایی' }), levelRow({ id: 2, name: 'نقره', sort: 2, counts: { agents: 2 } })]

/** A list of levels, each with its delete; a level agents are on cannot go. */
function Levels() {
  const list = useRows({
    query: agencyLevelsQuery,
    list: 'levels',
    reorder: (ids) => api.post('/agency/levels/reorder', { ids }),
    remove: (level) => api.delete(`/agency/levels/${level.id}`),
  })
  const [removing, setRemoving] = useState<AgencyLevelRow | null>(null)

  return (
    <>
      {list.rows.map((level) => (
        <button key={level.id} type="button" onClick={() => setRemoving(level)}>
          {`حذف ${level.name}`}
        </button>
      ))}
      <RemoveConfirm
        row={removing}
        remove={list.remove}
        title="حذف سطح"
        description={(level) => `«${level.name}» حذف می‌شود.`}
        details={() => 'هیچ نماینده‌ای روی این سطح نیست.'}
        kept={(level) => (level.counts.agents > 0 ? `${level.counts.agents} نماینده روی «${level.name}» هستند.` : null)}
        removed="سطح حذف شد"
        onClose={() => setRemoving(null)}
      />
    </>
  )
}

async function showLevels() {
  server().on('GET', '/api/admin/agency/levels', json({ levels: LEVELS }))
  render(<Levels />, { wrapper: providers().wrapper })
  await screen.findByRole('button', { name: 'حذف نقره' })
}

/** The dialog open now. */
const openDialog = () => within(document.querySelector<HTMLElement>('dialog[open]') ?? document.body)

describe('a row’s delete', () => {
  it('says a refusal in the dialog that asked, which stays with the row — no toast besides', async () => {
    vi.spyOn(toast, 'error')
    server().on('DELETE', '/api/admin/agency/levels/1', refusal(409, 'این سطح همین حالا در استفاده است.'))
    await showLevels()

    fireEvent.click(screen.getByRole('button', { name: 'حذف طلایی' }))
    fireEvent.click(openDialog().getByRole('button', { name: 'حذف' }))

    await until(() => expect(openDialog().getByText('این سطح همین حالا در استفاده است.')).toBeTruthy())
    expect(toast.error).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'حذف طلایی' })).toBeTruthy()

    // Closed and opened again, the last refusal is gone with that opening.
    fireEvent.click(openDialog().getByRole('button', { name: 'انصراف' }))
    fireEvent.click(screen.getByRole('button', { name: 'حذف طلایی' }))
    expect(openDialog().queryByText('این سطح همین حالا در استفاده است.')).toBeNull()
  })

  it('offers nothing to delete for a row that already says it cannot go, only why and a way out', async () => {
    await showLevels()

    fireEvent.click(screen.getByRole('button', { name: 'حذف نقره' }))

    expect(openDialog().getByText('2 نماینده روی «نقره» هستند.')).toBeTruthy()
    expect(openDialog().queryByRole('button', { name: 'حذف' })).toBeNull()
    // The way out has the focus — the dialog's own «بستن», beside its ✕.
    expect(document.activeElement?.textContent).toBe('بستن')
    expect(server().sent('DELETE', '/api/admin/agency/levels/2')).toHaveLength(0)
  })

  it('takes the row out once it is gone', async () => {
    vi.spyOn(toast, 'success')
    server().on('DELETE', '/api/admin/agency/levels/1', noContent)
    await showLevels()

    fireEvent.click(screen.getByRole('button', { name: 'حذف طلایی' }))
    fireEvent.click(openDialog().getByRole('button', { name: 'حذف' }))

    await until(() => expect(screen.queryByRole('button', { name: 'حذف طلایی' })).toBeNull())
    expect(toast.success).toHaveBeenCalledWith('سطح حذف شد')
  })
})
