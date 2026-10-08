import { useRef, useState } from 'react'
import { LogOut } from 'lucide-react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { useRail } from '@/components/shell/rail'
import { useShell } from '@/components/shell/shell-context'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { InitialsMark } from '@/components/user-identity'
import { useAuth, useSession } from '@/lib/auth'
import { contentSide } from '@/lib/direction'
import { toastFailure } from '@/lib/failure'
import { confirmLeave } from '@/lib/use-unsaved-guard'

const SIGN_OUT = 'خروج از حساب'

/**
 * Who is signed in, at the foot of the sidebar: their mark, name and role, and the button that signs out in one press —
 * an icon in the danger outline — with no menu, by decision: the theme and the owner's login are settings
 * («تنظیمات پنل»). On the rail, the button alone.
 */
export function AccountRow() {
  const { logout } = useAuth()
  const session = useSession()
  const { panel } = useShell()
  const collapsed = useRail()
  const navigate = useNavigate()
  const [leaving, setLeaving] = useState(false)
  // A press under way — its question asked, or its request sent —: a second press, before the button turns busy, is none.
  const pressed = useRef(false)

  async function signOut() {
    if (pressed.current) return
    pressed.current = true
    try {
      // Signing out drops every unsaved draft: asked first.
      const leave = await confirmLeave()
      if (!leave) return
      setLeaving(true)
      try {
        await logout()
      } catch (error) {
        // The server did not take it (it could not be reached): the session is still open, its drafts too, and the admin
        // is told.
        leave.keep()
        setLeaving(false)
        toastFailure(error, 'خروج انجام نشد')
        return
      }
      toast.success('از حساب خود خارج شدید')
      navigate('/login', { replace: true })
    } finally {
      pressed.current = false
    }
  }

  // While the sign-out runs the button waits (busy): a second press sends nothing more.
  const button = (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button variant="danger-outline" size="icon" icon={LogOut} aria-label={SIGN_OUT} busy={leaving} onClick={() => void signOut()} className={collapsed ? 'mx-auto' : undefined} />
      </TooltipTrigger>
      <TooltipContent side={collapsed ? contentSide() : 'top'}>{SIGN_OUT}</TooltipContent>
    </Tooltip>
  )

  if (collapsed) return button

  return (
    <div role="group" aria-label="حساب کاربری" className="flex items-center gap-2.5 py-1.5 ps-1.5">
      <InitialsMark name={session.name} />
      <div className="grid min-w-0 flex-1 leading-tight">
        {/* The name as the account signs in: the owner's login, or an agent's «@bot» — in a <bdi>, so the «@» stays in front. */}
        <span className="truncate text-body font-medium text-foreground">
          <bdi>{session.name}</bdi>
        </span>
        <span className="truncate text-caption text-muted-foreground">{panel.role}</span>
      </div>
      {button}
    </div>
  )
}
