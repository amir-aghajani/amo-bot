import type { InstallStatus } from '@/lib/api-types'

/** The installer's steps, in order. */
export type Step = 'requirements' | 'database' | 'admin' | 'site' | 'finish'

export const STEPS: { key: Step; label: string }[] = [
  { key: 'requirements', label: 'پیش‌نیازها' },
  { key: 'database', label: 'دیتابیس' },
  { key: 'admin', label: 'ورود پنل' },
  { key: 'site', label: 'سایت و ربات' },
  { key: 'finish', label: 'پایان' },
]

/** The first step the installation still needs, where the installer opens. */
export function firstOpen(status: InstallStatus): Step {
  if (!status.ready) return 'requirements'
  if (!status.database.tables) return 'database'
  if (!status.admin.configured) return 'admin'
  return 'site'
}

/**
 * Which steps are done, so the bar can show them and go back to them. The site's leaves no mark the server reports (its
 * name and address always have a value): it is done once the installer went past it — the end opens only from there.
 */
export function isDone(step: Step, status: InstallStatus, current: Step): boolean {
  switch (step) {
    case 'requirements':
      return status.ready
    case 'database':
      return status.database.tables
    case 'admin':
      return status.admin.configured
    case 'site':
      return current === 'finish'
    default:
      return false
  }
}

/** A step's props: where the installation stands, and what to do once the step went through. */
export interface StepProps {
  status: InstallStatus
  /** The step went through: where the installation stands now, and the step to show next. */
  onDone: (status: InstallStatus, next: Step) => void
}
