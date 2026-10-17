import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { WithTokens } from '@/components/bot-texts/tokens'

/*
 * A sentence about a bot text (components/bot-texts/tokens): its variables, commands, handles and a start link's
 * parameter each set apart left to right — bare in Persian, «/free» reads «free/» and «@BotFather» «BotFather@» —, an
 * address or an email left whole as it is.
 */

/** The runs set apart in `text`, as WithTokens draws it. */
function apart(text: string): string[] {
  const { container } = render(<WithTokens text={text} />)
  return Array.from(container.querySelectorAll('bdi[dir="ltr"]'), (bdi) => bdi.textContent ?? '')
}

describe('a sentence about a bot text', () => {
  it('sets apart its variables, commands, handles and a start link’s parameter', () => {
    expect(apart('صفحه «اکانت تست» منوی اصلی (و لینک ?start=usertest و دستور /free)؛ یک دکمه برای هر سرور زیر آن می‌آید.')).toEqual(['?start=usertest', '/free'])
    expect(apart('در @BotFather با دستور /newbot یک ربات بسازید.')).toEqual(['@BotFather', '/newbot'])
    expect(apart('سرویس %client% به مشتری دیگر')).toEqual(['%client%'])
  })

  it('keeps every character as written', () => {
    const text = 'منوی ربات با /menu می‌آید؛ مقدار %outcome% در پیام.'
    const { container } = render(<WithTokens text={text} />)

    expect(container.textContent).toBe(text)
  })

  it('leaves an address, an email and a fraction whole', () => {
    expect(apart('لینک https://t.me/amo_bot?start=usertest را بفرستید یا به help@example.com بنویسید؛ ۱/۲ حجم.')).toEqual([])
  })
})
