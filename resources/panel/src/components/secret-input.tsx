import { useState, type ComponentProps } from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

/**
 * A password/token field with a reveal toggle. The wrapper is LTR so the caret and selection behave
 * for Latin secrets; like every LTR field the text is right-aligned (index.css), so the toggle sits
 * at the far right next to where the value ends. A secret the shop keeps (a panel's, the database's,
 * a mail server's) is no login of the browser's: `new-password` keeps a password manager from filling
 * the panel's own sign-in into it — which `off` does not for a password field — and from making the
 * card unsaved without a key pressed. The owner's own current password says so itself.
 */
export function SecretInput({ className, ...props }: Omit<ComponentProps<typeof Input>, 'type'>) {
  const [reveal, setReveal] = useState(false)

  return (
    <div className="relative" dir="ltr">
      <Input type={reveal ? 'text' : 'password'} autoComplete="new-password" spellCheck={false} className={cn('pe-9', className)} {...props} />
      <button
        type="button"
        onClick={() => setReveal((value) => !value)}
        aria-label={reveal ? 'پنهان کردن' : 'نمایش'}
        aria-pressed={reveal}
        className="absolute inset-y-0 end-0 flex w-9 items-center justify-center rounded-e-lg text-faint transition-colors outline-none hover:text-foreground focus-visible:focus-ring"
      >
        {reveal ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
      </button>
    </div>
  )
}
