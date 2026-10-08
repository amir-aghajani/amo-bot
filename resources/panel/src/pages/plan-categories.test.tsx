import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { PlanCategoriesResponse, PlanCategoryRow } from '@/lib/api-types'
import { PlanCategoriesPage } from '@/pages/plan-categories'
import { providers, until } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * The plans' categories (pages/plan-categories): the page counts them once their list was read — never a zero it does
 * not know —, and says the bot's rule as the bot keeps it: the category step comes as soon as one category is active.
 */

const MONTHLY: PlanCategoryRow = { id: 1, name: 'ماهانه', is_active: true, sort: 1, counts: { plans: 2 }, created_at: '2026-10-01T10:00:00Z', updated_at: '2026-10-01T10:00:00Z' }

function open(answer = json({ categories: [MONTHLY] } satisfies PlanCategoriesResponse)) {
  server().on('GET', '/api/admin/plans/categories', answer)
  render(<PlanCategoriesPage />, { wrapper: providers().wrapper })
}

const title = () => screen.getByRole('heading', { level: 1 }).textContent

describe('the categories page', () => {
  it('counts the categories it read', async () => {
    open()

    await until(() => expect(title()).toBe('دسته‌بندی‌ها۱'))
  })

  it('counts nothing when its list could not be read', async () => {
    open(refusal(500, 'خطای سرور.'))

    expect(await screen.findByText('لیست دسته‌ها بارگذاری نشد.')).toBeTruthy()
    expect(title()).toBe('دسته‌بندی‌ها')
  })

  it('says the bot asks for a category first as soon as one is active', async () => {
    open()

    expect(await screen.findByText(/تا وقتی دسته فعالی هست، مشتری در ربات اول دسته را انتخاب می‌کند/)).toBeTruthy()
    expect(screen.queryByText(/بیش از یک دسته/)).toBeNull()
  })
})
