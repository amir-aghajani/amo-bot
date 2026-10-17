import { useState, type ComponentProps } from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

/**
 * A password or token field with a reveal toggle, LTR so the caret and selection behave for Latin secrets. Defaults to
 * `autoComplete="new-password"`: a secret the shop keeps (a panel's, the database's) is not the browser's login, and a
 * password manager — which ignores `off` on a password field — would otherwise fill in the panel's own sign-in and make
 * the card unsaved. A sign-in form passes `current-password`.
 */
export function SecretInput({ className, ...props }: Omit<ComponentProps<typeof Input>, 'type'>) {
  const [reveal, setReveal] = useState(false)

  return (
    <div className="relative" dir="ltr">
      <Input type={reveal ? 'text' : 'password'} autoComplete="new-password" spellCheck={false} className={cn('pe-9', className)} {...props} />
      {/* A toggle keeps one name — its pressed state says whether the value shows —, as the icon alone changes. */}
      <button
        type="button"
        onClick={() => setReveal((value) => !value)}
        aria-label="نمایش"
        aria-pressed={reveal}
        className="absolute inset-y-0 end-0 flex w-9 items-center justify-center rounded-e-lg text-faint transition-colors outline-none hover:text-foreground focus-visible:focus-ring"
      >
        {reveal ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
      </button>
    </div>
  )
}
