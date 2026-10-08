import type { ReactNode, Ref } from 'react'
import { InfoTip } from '@/components/info-tip'
import { Badge } from '@/components/ui/badge'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/utils'

export interface PageHeaderProps {
  title: ReactNode
  /** One or two lines under the title, in secondary ink. */
  description?: ReactNode
  /** The page's actions, at the far end of the title row (the primary one last). */
  actions?: ReactNode
  /** The actions' place, for actions drawn from elsewhere (a section's own, SectionActions); it takes no room while empty. */
  actionsRef?: Ref<HTMLDivElement>
  /** How many rows the page lists, as a neutral chip beside the title. */
  count?: number
  /** A longer explanation behind an ⓘ beside the title. */
  info?: ReactNode
  /** The greeting of the overview: the display size, a step up. */
  voice?: boolean
}

/** The head of every page, as the Console's: the title (with its count and ⓘ), the description under it, the actions at the end. */
export function PageHeader({ title, description, actions, actionsRef, count, info, voice = false }: PageHeaderProps) {
  return (
    <header className="grid gap-x-6 gap-y-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
      <div className="grid min-w-0 gap-1">
        <h1 className={cn('flex min-w-0 flex-wrap items-center gap-2 md:min-h-8', voice ? 'text-display font-medium' : 'text-title font-medium')}>
          <span className="min-w-0 wrap-anywhere">{title}</span>
          {count !== undefined && <Badge className="h-[22px] min-w-[22px] rounded-md px-1.5 text-footnote tabular">{formatNumber(count)}</Badge>}
          {info && <InfoTip label="توضیح">{info}</InfoTip>}
        </h1>
        {description && <p className="max-w-3xl text-body text-muted-foreground">{description}</p>}
      </div>
      {(actions || actionsRef) && (
        <div ref={actionsRef} className="flex flex-wrap items-center gap-2 empty:hidden md:justify-end">
          {actions}
        </div>
      )}
    </header>
  )
}
