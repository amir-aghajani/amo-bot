import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { StatCard } from '@/components/stat-card'

/*
 * A headline figure (components/stat-card): a skeleton while its read runs; the figure with how it moved once it came
 * in; «—» when there is none to show — never a zero it does not know, nor a comparison without a figure.
 */

const skeletons = () => document.querySelectorAll('[data-slot="skeleton"]')

describe('a stat card', () => {
  it('shows its figure, its unit and how it moved', () => {
    render(<StatCard label="درآمد" value="۱۵۰٬۰۰۰" unit="تومان" change={50} changeLabel="نسبت به ۳۰ روز قبل از آن" />)

    expect(screen.getByText('۱۵۰٬۰۰۰')).toBeTruthy()
    expect(screen.getByText('تومان')).toBeTruthy()
    expect(screen.getByText('نسبت به ۳۰ روز قبل از آن')).toBeTruthy()
  })

  it('is «—» without a figure, its comparison gone and its own hint kept', () => {
    render(<StatCard label="درآمد" value={undefined} unit="تومان" change={null} hint={undefined} />)

    expect(screen.getByText('—')).toBeTruthy()
    expect(screen.queryByText('تومان')).toBeNull()
    expect(screen.queryByText('بدون مقایسه')).toBeNull()
    expect(skeletons()).toHaveLength(0)
  })

  it('keeps a hint that says what it counts, figure or not', () => {
    render(<StatCard label="معرف‌ها" value={undefined} hint="مشتری‌هایی که لینکشان کسی را آورده" />)

    expect(screen.getByText('—')).toBeTruthy()
    expect(screen.getByText('مشتری‌هایی که لینکشان کسی را آورده')).toBeTruthy()
  })

  it('waits under a skeleton while its read runs', () => {
    render(<StatCard label="درآمد" value={undefined} loading />)

    expect(skeletons()).toHaveLength(2)
    expect(screen.queryByText('—')).toBeNull()
  })
})
