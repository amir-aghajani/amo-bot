import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { TextModal } from '@/components/bot-texts/text-modal'
import { LeaveQuestion } from '@/components/leave-question'
import type { BotTextRow } from '@/lib/api-types'
import { providers, until } from '@/test/render'
import { refusal, server } from '@/test/server'

/*
 * A bot text's editor (components/bot-texts/text-modal): what the save would refuse is shown as the admin types — in
 * the server's own wording (wordingProblem()) —, and goes once the wording is one Telegram takes; the tags such a line
 * names (its own, or the server's refusal's) read as typed in the Persian sentence, each set apart in Unicode's isolates.
 * The counter counts the wording the server would keep, and «متن پیش‌فرض» asks before it drops a wording typed and not
 * saved, which nothing brings back.
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
const apart = (run: string) => `\u{2068}${run}\u{2069}`

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

  it('has nothing to save for a trailing line break the server would trim, but does save a part’s leading line breaks', () => {
    const part: BotTextRow = { ...WELCOME, key: 'renewal_hint', kind: 'part', value: 'تمدید با یک لمس.', default: 'تمدید با یک لمس.' }
    const { rerender } = render(<TextModal text={WELCOME} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'سلام!\n' } })
    expect(held()).toBe(true)

    rerender(<TextModal text={part} onClose={vi.fn()} onSaved={vi.fn()} />)
    fireEvent.change(screen.getByRole('textbox'), { target: { value: '\nتمدید با یک لمس. ' } })
    expect(held()).toBe(false)
  })

  it('wraps the selection in a tag from the toolbar, the selection kept between them, the field focused', () => {
    render(<TextModal text={WELCOME} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })
    const field = screen.getByRole('textbox') as HTMLTextAreaElement

    field.setSelectionRange(0, 4)
    fireEvent.click(screen.getByRole('button', { name: 'پررنگ' }))

    expect(field.value).toBe('<b>سلام</b>!')
    expect([document.activeElement, field.selectionStart, field.selectionEnd]).toEqual([field, 3, 7])
  })

  it('previews the wording in a figure named for assistive tech, its paragraphs each in its own direction', () => {
    render(<TextModal text={WELCOME} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'سلام\nhttps://t.me/amo' } })

    const preview = screen.getByRole('figure', { name: 'پیش‌نمایش در تلگرام' })
    expect(preview.querySelector('.\\[unicode-bidi\\:plaintext\\]')?.textContent).toBe('سلام\nhttps://t.me/amo')
  })

  it('previews a variable the bot fills with another text’s wording as that wording looks, a plain one as typed', () => {
    const card: BotTextRow = {
      ...WELCOME,
      key: 'pay_online_card',
      kind: 'part',
      variables: [
        { name: 'name', description: 'نام', sample: '<Amir>', html: false, required: false },
        { name: 'card_holder', description: 'صاحب کارت', sample: '\n👤 به نام <b>سارا احمدی</b>', html: true, required: false },
      ],
    }
    render(<TextModal text={card} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    fireEvent.change(screen.getByRole('textbox'), { target: { value: '%name%%card_holder%' } })

    const preview = screen.getByRole('figure', { name: 'پیش‌نمایش در تلگرام' })
    expect(preview.querySelector('strong')?.textContent).toBe('سارا احمدی')
    expect(preview.textContent).toContain('<Amir>')
    expect(preview.textContent).not.toContain('<b>')
  })

  it('counts the wording as the server would keep it: spaces at its end are no characters', () => {
    render(<TextModal text={{ ...WELCOME, kind: 'button', html: false, limit: 5 }} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'خرید   ' } })

    const counter = screen.getByText(/از ۵ کاراکتر/)
    expect(counter.textContent).toBe('۴ از ۵ کاراکتر')
    expect(counter.className).not.toContain('text-danger')
  })

  it('counts a wording Telegram shows as nothing — a no-break space, formatting around a zero-width one — as none, and warns it is empty', () => {
    render(<TextModal text={WELCOME} onClose={vi.fn()} onSaved={vi.fn()} />, { wrapper: providers().wrapper })

    fireEvent.change(screen.getByRole('textbox'), { target: { value: ' ' } })
    expect(screen.getByText(/از ۴٬۰۹۶ کاراکتر/).textContent).toBe('۰ از ۴٬۰۹۶ کاراکتر، بدون تگ‌ها')
    expect(screen.getByText('متن نمی‌تواند خالی باشد.')).toBeTruthy()

    fireEvent.change(screen.getByRole('textbox'), { target: { value: '<b>​</b>' } })
    expect(screen.getByText(/از ۴٬۰۹۶ کاراکتر/).textContent).toBe('۰ از ۴٬۰۹۶ کاراکتر، بدون تگ‌ها')

    fireEvent.change(screen.getByRole('textbox'), { target: { value: '<b>سلام </b>' } })
    expect(screen.getByText(/از ۴٬۰۹۶ کاراکتر/).textContent).toBe('۵ از ۴٬۰۹۶ کاراکتر، بدون تگ‌ها')
    expect(screen.queryByText('متن نمی‌تواند خالی باشد.')).toBeNull()
  })
})

/** A text the shop reworded. */
const CUSTOMIZED: BotTextRow = { ...WELCOME, value: 'سلام دوست من!', customized: true }

const wording = () => screen.getByRole('textbox') as HTMLTextAreaElement
const pressDefault = () => fireEvent.click(screen.getByRole('button', { name: 'متن پیش‌فرض' }))

/** The editor, with the panel's unsaved-changes question it may ask. */
function showWithQuestion(text: BotTextRow) {
  render(
    <>
      <LeaveQuestion />
      <TextModal text={text} onClose={vi.fn()} onSaved={vi.fn()} />
    </>,
    { wrapper: providers().wrapper },
  )
}

describe('the default wording', () => {
  it('takes the saved wording’s place at once — «بازگردانی تغییرات» brings that back —, and is held once it is in', () => {
    showWithQuestion(CUSTOMIZED)

    pressDefault()

    expect(wording().value).toBe('سلام!')
    expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull()
    expect(screen.getByRole('button', { name: 'متن پیش‌فرض' }).getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(screen.getByRole('button', { name: 'بازگردانی تغییرات' }))
    expect(wording().value).toBe('سلام دوست من!')
  })

  it('asks before it drops a wording typed and not saved, and keeps it when the admin stays', async () => {
    showWithQuestion(CUSTOMIZED)
    fireEvent.change(wording(), { target: { value: 'سلام دوست عزیزم!' } })

    pressDefault()
    const question = await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })
    fireEvent.click(within(question).getByRole('button', { name: 'ماندن' }))
    await until(() => expect(screen.queryByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).toBeNull())
    expect(wording().value).toBe('سلام دوست عزیزم!')

    pressDefault()
    fireEvent.click(within(await screen.findByRole('dialog', { name: 'تغییرات ذخیره نشده‌اند' })).getByRole('button', { name: 'رها کردن تغییرات' }))
    await until(() => expect(wording().value).toBe('سلام!'))
  })
})
