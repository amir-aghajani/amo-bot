import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'

/*
 * The text fields' shared look (components/ui/input's fieldClasses): a read-only field — a reply held while it sends,
 * codes shown to copy — is drawn flat and quieter in both themes, never as a field to type in, and stays focusable.
 */

describe('a text field', () => {
  it('draws its hairline in a colour each theme sets, so the focus and a refusal show over it in the dark theme too', () => {
    render(
      <>
        <Input aria-label="نام" aria-invalid />
        <Textarea aria-label="توضیح" />
      </>,
    )

    for (const field of [screen.getByLabelText('نام'), screen.getByLabelText('توضیح')]) {
      const looks = field.className.split(' ')
      expect(looks).toEqual(expect.arrayContaining(['border-field-line', 'focus-visible:focus-field', 'aria-invalid:border-danger-line']))
      // A plain `dark:` border or hover border comes later in the stylesheet than the focus and the refusal, and wins.
      expect(looks.filter((look) => /^dark:(hover:)?border-/.test(look))).toEqual([])
    }
  })

  it('is 16px on a phone, where a smaller one zooms the page as it takes the focus', () => {
    render(<Input aria-label="جستجو" />)
    expect(screen.getByLabelText('جستجو').className.split(' ')).toContain('max-md:text-base')
  })

  it('is a finger’s 44px tall on a phone, 32px from `md`', () => {
    render(<Input aria-label="نام" />)
    expect(screen.getByLabelText('نام').className.split(' ')).toEqual(expect.arrayContaining(['h-8', 'max-md:h-11']))
  })
})

describe('a read-only text field', () => {
  it('has a look of its own in either theme — a flat fill, quieter words, no hover — and keeps the focus', () => {
    render(
      <>
        <Input aria-label="کد" readOnly value="NOWRUZ-1" />
        <Textarea aria-label="پاسخ" readOnly value="در حال ارسال" />
      </>,
    )

    for (const field of [screen.getByLabelText('کد'), screen.getByLabelText('پاسخ')]) {
      const looks = field.className.split(' ')
      expect(looks).toEqual(expect.arrayContaining(['[&[readonly]]:bg-fill', '[&[readonly]]:text-muted-foreground', '[&[readonly]]:hover:border-border', 'dark:[&[readonly]]:bg-transparent']))
      field.focus()
      expect(document.activeElement).toBe(field)
    }
  })
})
