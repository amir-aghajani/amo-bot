import { toast } from 'sonner'

/** Copy text to the clipboard and say how it went: `done` on success, a hint to select it by hand otherwise. */
export async function copyText(text: string, done: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(text)
    toast.success(done)
  } catch {
    toast.error('کپی انجام نشد؛ آن را دستی انتخاب کنید.')
  }
}
