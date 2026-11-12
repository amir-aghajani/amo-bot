import type { ReactNode } from 'react'
import { APPEARANCE_DESCRIPTION, AppearanceCard } from '@/components/appearance-card'
import { BackupsDescription, BackupsSection } from '@/components/backups/backups-section'
import { SectionedPage } from '@/components/sectioned-page'
import { APPEARANCE_SECTION, BACKUPS_SECTION } from '@/components/shell/nav'

const SECTIONS = [BACKUPS_SECTION, APPEARANCE_SECTION]

/** What the header says of each section. */
const DESCRIPTIONS: Record<(typeof SECTIONS)[number]['value'], ReactNode> = {
  backups: <BackupsDescription />,
  appearance: APPEARANCE_DESCRIPTION,
}

/**
 * An agent's «تنظیمات پنل»: their shop's backups — how often one goes to the report group is the owner's to set — and
 * the panel's look in this browser (their shop's settings are the bot settings).
 */
export function SettingsPage() {
  return (
    <SectionedPage sections={SECTIONS} width="narrow" header={(current) => ({ title: 'تنظیمات پنل', description: DESCRIPTIONS[current.value] })}>
      {{ backups: <BackupsSection />, appearance: <AppearanceCard /> }}
    </SectionedPage>
  )
}
