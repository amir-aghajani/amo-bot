import { createContext, useContext, type ReactElement, type ReactNode } from 'react'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { contentSide } from '@/lib/direction'

/** Whether a column of the sidebar is the desktop's icon rail (the phone's drawer never is), for everything drawn in it. */
export const RailContext = createContext(false)

export const useRail = () => useContext(RailContext)

/** On the rail, a control's words are its tooltip, beside it; in a full column they are on it and it needs none. */
export function RailTooltip({ label, children }: { label: ReactNode; children: ReactElement }) {
  const rail = useRail()
  if (!rail) return children

  return (
    <Tooltip>
      <TooltipTrigger asChild>{children}</TooltipTrigger>
      <TooltipContent side={contentSide()}>{label}</TooltipContent>
    </Tooltip>
  )
}
