import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { IconButton } from '@/components/icon-button'
import { Button } from '@/components/ui/button'

/*
 * The panels' buttons (components/ui/button): however small one is drawn — a row's «جزئیات», an icon alone —, its press
 * is a finger's 44px on a phone (index.css's `touch-target`); a link-styled one in its line of text keeps its own.
 */

const looks = (name: string) => screen.getByRole('button', { name }).className.split(' ')

describe('a button', () => {
  it('of any size presses as a finger’s 44px on a phone', () => {
    render(
      <>
        <Button size="sm">جزئیات</Button>
        <Button>دیدن نسخه تازه</Button>
        <IconButton aria-label="به‌روزرسانی">↻</IconButton>
      </>,
    )

    for (const name of ['جزئیات', 'دیدن نسخه تازه', 'به‌روزرسانی']) expect(looks(name), name).toContain('touch-target')
  })

  it('drawn as a link keeps its own press: it sits in a line of text', () => {
    render(<Button variant="link">پاک کردن مقدار ذخیره‌شده</Button>)

    expect(looks('پاک کردن مقدار ذخیره‌شده')).not.toContain('touch-target')
  })
})
