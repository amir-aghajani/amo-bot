import { describe, expect, it } from 'vitest'
import { handleLabel, idLabel, isolate, isolateMarkup } from '@/lib/direction'

/*
 * A Latin run in a Persian plain string (lib/direction): a toast, a dialog's title, an aria-label, the tab's name. Left
 * bare, the bidirectional algorithm hands a leading «@» to the run's far side — «@agent_bot» reads «agent_bot@» —, and
 * a tag's brackets and «/» scatter — «</b>» reads «<b/>» (measured in a browser); set apart in Unicode's isolates, the run
 * keeps its own direction and every character it had, as `<bdi>` keeps it in markup.
 */

/** U+2068 FIRST STRONG ISOLATE and U+2069 POP DIRECTIONAL ISOLATE, by their code points. */
const FSI = String.fromCodePoint(0x2068)
const PDI = String.fromCodePoint(0x2069)

describe('a run of text in a sentence', () => {
  it('is set apart in a first-strong isolate, every character of it kept', () => {
    expect(isolate('@agent_shop_bot')).toBe(`${FSI}@agent_shop_bot${PDI}`)
    expect(isolate('امیر')).toBe(`${FSI}امیر${PDI}`)
    expect(isolate('').length).toBe(2)
  })

  it('is a handle with its «@», and a row’s number with its «#»', () => {
    expect(handleLabel('agent_shop_bot')).toBe(`${FSI}@agent_shop_bot${PDI}`)
    expect(idLabel(12)).toBe(`${FSI}#12${PDI}`)
    expect(`سفارش ${idLabel(12)} باز هم تحویل نشد`).toBe(`سفارش ${FSI}#12${PDI} باز هم تحویل نشد`)
  })
})

/** `run` set apart, as isolateMarkup() sets each run of markup. */
const apart = (run: string) => `${FSI}${run}${PDI}`

describe('the markup in a sentence', () => {
  it('is set apart run by run: each tag, its brackets and «/» as typed', () => {
    expect(isolateMarkup('تگ <b> بسته نشده است؛ آخر آن </b> بگذارید.')).toBe(`تگ ${apart('<b>')} بسته نشده است؛ آخر آن ${apart('</b>')} بگذارید.`)
    expect(isolateMarkup('تگ <span> فقط به صورت <span class="tg-spoiler"> پذیرفته می‌شود.')).toBe(`تگ ${apart('<span>')} فقط به صورت ${apart('<span class="tg-spoiler">')} پذیرفته می‌شود.`)
    expect(isolateMarkup('داخل لینک (<a>) تگ <code> نمی‌شود گذاشت.')).toBe(`داخل لینک (${apart('<a>')}) تگ ${apart('<code>')} نمی‌شود گذاشت.`)
  })

  it('keeps a tag with what is glued to it as one run', () => {
    const example = '<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>'

    expect(isolateMarkup(`مثلا ${example}.`)).toBe(`مثلا ${apart(example)}.`)
  })

  it('sets apart an entity, a variable, an attribute, a quoted value and a bracket or a quote of its own — the sentence’s own stops stay its own', () => {
    expect(isolateMarkup('نماد < فقط برای شروع تگ است؛ برای نوشتن خودش &lt; بنویسید.')).toBe(`نماد ${apart('<')} فقط برای شروع تگ است؛ برای نوشتن خودش ${apart('&lt;')} بنویسید.`)
    expect(isolateMarkup('تگ <a> با > بسته نشده است.')).toBe(`تگ ${apart('<a>')} با ${apart('>')} بسته نشده است.`)
    expect(isolateMarkup('متغیر %subscription% باید در متن بماند.')).toBe(`متغیر ${apart('%subscription%')} باید در متن بماند.`)
    expect(isolateMarkup('بعد از «href» باید = و مقدار بیاید؛ مثلا href="…".')).toBe(`بعد از «href» باید = و مقدار بیاید؛ مثلا ${apart('href="…"')}.`)
    expect(isolateMarkup('مقدار href در تگ <a> را داخل "…" بنویسید.')).toBe(`مقدار href در تگ ${apart('<a>')} را داخل ${apart('"…"')} بنویسید.`)
    expect(isolateMarkup('مقدار href در تگ <a> با " بسته نشده است.')).toBe(`مقدار href در تگ ${apart('<a>')} با ${apart('"')} بسته نشده است.`)
  })

  it('leaves a sentence with no markup as it is', () => {
    expect(isolateMarkup('متن نمی‌تواند خالی باشد.')).toBe('متن نمی‌تواند خالی باشد.')
  })
})
