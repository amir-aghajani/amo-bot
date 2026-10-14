import type { ReactNode } from 'react'
import { Info } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Callout } from '@/components/callout'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import type { BotSettingsSaved } from '@/lib/api-types'

interface SwitchCardProps<F extends string> {
  /** The group's switch, by the field the server refuses it by. */
  field: F
  checked: boolean
  /** The group's PUT (`/bot/settings/{group}`) of the switch as set. */
  save: (values: Record<F, boolean>) => Promise<BotSettingsSaved>
  title: string
  description: string
  label: string
  hint: string
  /** How it goes, an `<li>` a line, in a note under the switch; none for a card the switch says all of. */
  children?: ReactNode
}

/** A bot-settings group that is one switch, as its own card — saved as the group's PUT, a refusal under the switch —, and how it goes under it. */
export function SwitchCard<F extends string>({ field, checked, save, title, description, label, hint, children }: SwitchCardProps<F>) {
  const group = useBotSettingsGroup({ [field]: checked } as Record<F, boolean>, save)
  const { values, set, error } = group

  return (
    <SectionCard form={group} title={title} description={description}>
      <SwitchRow label={label} hint={hint} checked={values[field]} onCheckedChange={(next) => set(field, next)} error={error(field)} />
      {children && (
        <Callout tone="info" icon={Info}>
          <ul className="grid list-disc gap-1.5 ps-4">{children}</ul>
        </Callout>
      )}
    </SectionCard>
  )
}

/** A note that each `what` is reported in the report group's «خریدها» topic, linked to that section: «هر انتقال در گروه گزارش‌ها …». */
export function ReportedInPurchases({ what }: { what: string }) {
  return (
    <>
      {`${what} در `}
      <TextLink to="/bot-settings/reports" inline>
        گروه گزارش‌ها
      </TextLink>
      ، تاپیک «خریدها»، گزارش می‌شود.
    </>
  )
}
