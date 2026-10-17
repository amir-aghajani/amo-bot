import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { SecretField } from '@/components/secret-field'
import type { SecretState } from '@/lib/api-types'

/*
 * A secret the server never sends back (components/secret-field): its hint shows as the placeholder in the placeholder's
 * own faint ink — never as if typed —, and while one is kept the field says that leaving it blank keeps it; clearing it
 * says it goes with the save instead.
 */

const KEPT: SecretState = { set: true, hint: '123…xyz' }

function Token({ stored }: { stored: SecretState }) {
  const [value, setValue] = useState('')
  const [clear, setClear] = useState(false)
  return <SecretField id="token" label="توکن" stored={stored} value={value} onChange={setValue} clear={clear} onClear={setClear} hint="از BotFather بگیرید." />
}

const field = () => screen.getByLabelText('توکن')
const described = () => (field().getAttribute('aria-describedby') ?? '').split(' ').map((id) => document.getElementById(id)?.textContent)

describe('a kept secret’s field', () => {
  it('says that leaving it blank keeps the kept one, read with the field, the hint in the placeholder’s faint ink', () => {
    render(<Token stored={KEPT} />)

    expect(described()).toEqual(['خالی بماند، همان مقدار ذخیره‌شده می‌ماند.', 'از BotFather بگیرید.'])
    expect(field().getAttribute('placeholder')).toBe('123…xyz')
    expect(field().className).toContain('placeholder:text-faint')
    expect(field().className).not.toMatch(/placeholder:text-foreground/)
  })

  it('cleared, says it goes with the save — no longer that it stays', () => {
    render(<Token stored={KEPT} />)

    fireEvent.click(screen.getByRole('button', { name: 'پاک کردن مقدار ذخیره‌شده' }))

    expect(field().getAttribute('placeholder')).toBe('با ذخیره حذف می‌شود')
    expect(screen.queryByText('خالی بماند، همان مقدار ذخیره‌شده می‌ماند.')).toBeNull()
    expect(described()).toEqual(['از BotFather بگیرید.'])
  })

  it('with nothing kept says nothing of keeping', () => {
    render(<Token stored={{ set: false, hint: '' }} />)

    expect(screen.queryByText('خالی بماند، همان مقدار ذخیره‌شده می‌ماند.')).toBeNull()
    expect(described()).toEqual(['از BotFather بگیرید.'])
  })
})
