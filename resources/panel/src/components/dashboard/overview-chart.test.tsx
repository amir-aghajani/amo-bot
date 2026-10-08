import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { OverviewChart } from '@/components/dashboard/overview-chart'

/*
 * The period's chart on the overview (components/dashboard/overview-chart): a skeleton while its read runs, «—» when it
 * has nothing to draw — never a skeleton that pulses for ever, nor a comparison without a figure.
 */

const skeletons = () => document.querySelectorAll('[data-slot="skeleton"]')

describe('the period’s chart', () => {
  it('is «—» with nothing to draw', () => {
    render(<OverviewChart series={undefined} kpis={undefined} />)

    expect(screen.getAllByText('—')).toHaveLength(2)
    expect(screen.queryByText('بدون مقایسه')).toBeNull()
    expect(skeletons()).toHaveLength(0)
  })

  it('waits under a skeleton while it is read', () => {
    render(<OverviewChart series={undefined} kpis={undefined} loading />)

    expect(skeletons().length).toBeGreaterThan(0)
    expect(screen.queryByText('—')).toBeNull()
  })
})
