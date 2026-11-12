import { Toaster as Sonner, toast } from 'sonner'
import { result } from '@/apps/app/telegram/haptics'

/* The app's toasts: short news at the top, clear of Telegram's header, in the app's colours, with the phone's touch. */

export function Toaster() {
  return (
    <Sonner
      position="top-center"
      dir="rtl"
      offset={{ top: 'calc(var(--app-top) + 0.5rem)' }}
      mobileOffset={{ top: 'calc(var(--app-top) + 0.5rem)' }}
      toastOptions={{
        style: {
          background: 'var(--app-section)',
          color: 'var(--app-text)',
          border: 'none',
          fontFamily: 'inherit',
          fontSize: '13px',
          lineHeight: '20px',
          fontWeight: 500,
          padding: '12px 16px',
          borderRadius: '16px',
          boxShadow: '0 1px 2px rgb(0 0 0 / 0.06), 0 12px 32px -12px rgb(0 0 0 / 0.28)',
        },
      }}
    />
  )
}

/** Good news, with the phone's success touch. */
export function told(message: string): void {
  result('success')
  toast.success(message)
}

/** Copy by the page's own selection: a box out of sight, its text selected and copied, then gone (focus back where it was). */
function copyBySelection(text: string): boolean {
  const box = document.createElement('textarea')
  box.value = text
  box.readOnly = true
  box.setAttribute('aria-hidden', 'true')
  box.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none;font-size:16px'
  const focused = document.activeElement
  document.body.append(box)
  try {
    box.select()
    box.setSelectionRange(0, text.length)
    return document.execCommand('copy')
  } catch {
    return false
  } finally {
    box.remove()
    if (focused instanceof HTMLElement && focused !== document.body) focused.focus({ preventScroll: true })
  }
}

/**
 * Copy text and say how it went. The clipboard's API first; where it is refused — web.telegram.org's frame has no
 * clipboard permission, an older client has no API — the page's own selection; the customer is asked to copy by hand
 * only when both fail.
 */
export async function copyText(text: string, done: string): Promise<void> {
  let copied: boolean
  try {
    await navigator.clipboard.writeText(text)
    copied = true
  } catch {
    copied = copyBySelection(text)
  }
  if (copied) {
    told(done)
    return
  }
  result('error')
  toast.error('کپی انجام نشد؛ آن را دستی انتخاب کنید.')
}
