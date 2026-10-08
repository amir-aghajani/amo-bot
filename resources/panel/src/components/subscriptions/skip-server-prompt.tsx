import type { ReactNode } from 'react'
import { ServerOff } from 'lucide-react'
import { Callout } from '@/components/callout'
import { ConfirmActions } from '@/components/confirm-modal'

interface SkipServerPromptProps {
  /** What the server did, in the admin's words from the API: «پنل «آلمان»: …». */
  reason: string
  /** What carrying on without that server means here. */
  question: ReactNode
  /** The button that carries on without the server. */
  skipLabel: string
  /** The button that drops the operation. */
  cancelLabel: string
  /** A skip that takes something away (a delete) is worded as dangerous. */
  destructive?: boolean
  busy?: boolean
  onSkip: () => void
  onCancel: () => void
}

/**
 * A server that did not answer, or refused, half-way through an operation: carry on without it — its
 * client stays there for the admin to remove — or cancel, which leaves the service as it was. Cancel
 * has the focus, like every "are you sure".
 */
export function SkipServerPrompt({ reason, question, skipLabel, cancelLabel, destructive, busy, onSkip, onCancel }: SkipServerPromptProps) {
  return (
    <Callout tone="warning" icon={ServerOff} role="alert">
      <div className="grid gap-2">
        <p className="font-medium">{reason}</p>
        <p className="text-muted-foreground">{question}</p>
        <ConfirmActions size="sm" cancelLabel={cancelLabel} confirmLabel={skipLabel} destructive={destructive} busy={busy} focusCancel onCancel={onCancel} onConfirm={onSkip} />
      </div>
    </Callout>
  )
}
