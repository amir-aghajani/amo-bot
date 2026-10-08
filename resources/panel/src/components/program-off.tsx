import type { ReactNode } from 'react'
import { TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { TextLink } from '@/components/text-link'

/** A program switched off (the referrals, the agency), on its own page: what that means now, and the way to its switch. */
export function ProgramOff({ to, action, children }: { to: string; action: string; children: ReactNode }) {
  return (
    <Callout tone="warning" icon={TriangleAlert}>
      {children}{' '}
      <TextLink to={to} inline className="font-medium">
        {action}
      </TextLink>
    </Callout>
  )
}
