import { fireEvent, render, screen } from '@testing-library/react'
import { toast } from 'sonner'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { CopyValue } from '@/apps/app/ui/fields'
import { Toaster } from '@/apps/app/ui/toaster'

vi.mock('@/apps/app/config', () => import('@/apps/app/test/config'))

/*
 * Copying (ui/toaster's copyText, behind every copy button): the clipboard's API first; where it is refused — the frame
 * of web.telegram.org has no clipboard permission — the page's own copy of a hidden box's text; the customer asked to
 * copy by hand only when both fail.
 */

const LINK = 'https://sub.example.net/sub/3d9e6b1f0a72'

/** The page's own copy (`document.execCommand('copy')`, which the test's window lacks), answering `copies`; what it copied. */
function pageCopy(copies: boolean) {
  const copied: string[] = []
  const execCommand = vi.fn((command: string) => {
    const box = document.querySelector('textarea')
    if (command === 'copy' && box !== null) copied.push(box.value.slice(box.selectionStart, box.selectionEnd))
    return copies
  })
  Object.defineProperty(document, 'execCommand', { value: execCommand, configurable: true })

  return { execCommand, copied }
}

/** The clipboard's API, refused as a frame without its permission refuses it. */
const refused = () => vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(new DOMException('Write permission denied.', 'NotAllowedError'))

/** The link's copy button, tapped; `done` its word once copied (each test its own: a toast outlives its test). */
function copyButton(done: string): HTMLElement {
  render(
    <>
      <CopyValue label="لینک اتصال" value={LINK} done={done} />
      <Toaster />
    </>,
  )
  const button = screen.getByRole('button', { name: /لینک اتصال/ })
  button.focus()
  fireEvent.click(button)

  return button
}

afterEach(() => {
  Reflect.deleteProperty(document, 'execCommand')
  toast.dismiss()
})

describe('a copy button', () => {
  it('copies by the clipboard’s API, and says so', async () => {
    const write = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue()
    const page = pageCopy(true)
    copyButton('کپی شد')

    expect(await screen.findByText('کپی شد')).toBeTruthy()
    expect(write).toHaveBeenCalledWith(LINK)
    expect(page.execCommand).not.toHaveBeenCalled()
  })

  it('copies by the page itself where the clipboard’s API is refused — and leaves nothing behind', async () => {
    refused()
    const page = pageCopy(true)
    const button = copyButton('لینک کپی شد')

    expect(await screen.findByText('لینک کپی شد')).toBeTruthy()
    expect(page.copied).toEqual([LINK])
    expect(document.querySelector('textarea')).toBeNull()
    expect(document.activeElement).toBe(button)
  })

  it('asks the customer to copy by hand only when both fail', async () => {
    refused()
    pageCopy(false)
    copyButton('لینک اتصال کپی شد')

    expect(await screen.findByText('کپی انجام نشد؛ آن را دستی انتخاب کنید.')).toBeTruthy()
    expect(screen.queryByText('لینک اتصال کپی شد')).toBeNull()
    expect(document.querySelector('textarea')).toBeNull()
  })
})
