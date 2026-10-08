import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { TextModal } from '@/components/bot-texts/text-modal'
import type { BotTextRow } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { refusal, server } from '@/test/server'

/*
 * A bot text's editor (components/bot-texts/text-modal): what the save would refuse is said as the admin types — the
 * server's own words (wordingProblem()) —, and goes once the wording is one Telegram takes; the tags such a line names —
 * in its words or the server's own refusal — read as typed in the Persian sentence, each set apart in Unicode's isolates.
 */

const WELCOME: BotTextRow = {
  key: 'welcome',
  group: 'start',
  kind: 'message',
  html: true,
  limit: 4096,
  title: 'خوش‌آمد',
  description: 'وقتی مشتری /start می‌زند.',
  variables: [],
  default: 'سلام!',
  value: 'سلام!',
  customized: false,
}

/** U+2068 FIRST STRONG ISOLATE and U+2069 POP DIRECTIONAL ISOLATE: a run kept whole, as typed. */
const apart = (run: string) => `⁨${run}⁩`

const UNCLOSED = 'تگ <b> بسته نشده است؛ آخر آن </b> بگذارید.'
const UNCLOSED_SHOWN = `تگ ${apart('<b>')} بسته نشده است؛ آخر آن ${apart('</b>')} بگذارید.`

/** The save, held: the wording says nothing the server would keep otherwise. */
const held = () => screen.getByRole('button', { name: 'ذخیره متن' }).getAttribute('aria-disabled') === 'true'

describe('a bot text’s editor', () => {
  it('warns of what Telegram would not take before the save is asked, its tags as typed', () => {
    render(<TextModal text={WELCOME} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })
    const field = screen.getByRole('textbox')
    expect(screen.queryByText(/بسته نشده است/)).toBeNull()

    fireEvent.change(field, { target: { value: '<b>سلام' } })
    expect(screen.getByText(UNCLOSED_SHOWN)).toBeTruthy()

    fireEvent.change(field, { target: { value: '<b>سلام</b>' } })
    expect(screen.queryByText(/بسته نشده است/)).toBeNull()
  })

  it('says the server’s refusal under the wording, its tags as typed', async () => {
    server().on('PUT', '/api/admin/bot/texts/welcome', refusal(422, UNCLOSED, { value: [UNCLOSED] }))
    render(<TextModal text={WELCOME} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    fireEvent.change(screen.getByRole('textbox'), { target: { value: '<b>سلام' } })
    fireEvent.click(screen.getByRole('button', { name: 'ذخیره متن' }))

    await until(() => expect(screen.getByRole('textbox').getAttribute('aria-invalid')).toBe('true'))
    expect(screen.getAllByText(UNCLOSED_SHOWN)).toHaveLength(1)
    expect(screen.queryByText(UNCLOSED)).toBeNull()
  })

  it('has nothing to save for a wording the server would keep as it is — a line break at its end —, and a part’s leading line breaks to save', () => {
    const part: BotTextRow = { ...WELCOME, key: 'renewal_hint', kind: 'part', value: 'تمدید با یک لمس.', default: 'تمدید با یک لمس.' }
    const { rerender } = render(<TextModal text={WELCOME} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'سلام!\n' } })
    expect(held()).toBe(true)

    rerender(<TextModal text={part} onClose={vi.fn()} onSaved={vi.fn()} />)
    fireEvent.change(screen.getByRole('textbox'), { target: { value: '\nتمدید با یک لمس. ' } })
    expect(held()).toBe(false)
  })
})
