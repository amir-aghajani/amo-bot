import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Rating } from '@/components/rating'

/* A customer's rating (components/rating) — a review's, a closed ticket's —: five stars, as many filled as given, read as one picture. */

describe('a rating', () => {
  it('fills as many of its five stars as given, read as «۳ از ۵»', () => {
    render(<Rating rating={3} />)

    const stars = screen.getByRole('img', { name: '۳ از ۵' })
    expect(stars.querySelectorAll('svg')).toHaveLength(5)
    expect(stars.querySelectorAll('svg.fill-current')).toHaveLength(3)
  })
})
