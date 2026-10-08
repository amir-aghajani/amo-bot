import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AgencyTermsFields, type AgencyTerms } from '@/components/agency/agency-terms-fields'
import type { AgencyLevelsResponse } from '@/lib/api-types'
import { providers } from '@/test/render'
import { json, refusal, server } from '@/test/server'

/*
 * An agent's terms (components/agency/agency-terms-fields — approving a request, changing an agent): the level picker
 * says when the levels could not be read, and when there are none to pick yet — never an empty list without a word.
 */

function Terms() {
  const [values, setValues] = useState<AgencyTerms>({ level_id: '', credit_limit: '' })
  return <AgencyTermsFields values={values} set={(key, value) => setValues((current) => ({ ...current, [key]: value }))} error={() => undefined} />
}

describe('the level picker', () => {
  it('says the levels could not be read, with a way to ask again', async () => {
    let answer = refusal(500, 'خطای سرور.')
    server().on('GET', '/api/admin/agency/levels', () => answer)
    render(<Terms />, { wrapper: providers().wrapper })

    expect((await screen.findByRole('alert')).textContent).toContain('لیست سطح‌ها بارگذاری نشد.')

    answer = json({ levels: [] } satisfies AgencyLevelsResponse)
    fireEvent.click(screen.getByRole('button', { name: 'تلاش دوباره' }))
    expect(await screen.findByText('هنوز سطحی ندارید؛ از بخش «سطح‌ها» بسازید.')).toBeTruthy()
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('says there are none to pick yet', async () => {
    server().on('GET', '/api/admin/agency/levels', json({ levels: [] } satisfies AgencyLevelsResponse))
    render(<Terms />, { wrapper: providers().wrapper })

    expect(await screen.findByText('هنوز سطحی ندارید؛ از بخش «سطح‌ها» بسازید.')).toBeTruthy()
  })
})
