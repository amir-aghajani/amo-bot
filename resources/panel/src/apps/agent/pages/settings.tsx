import { APPEARANCE_DESCRIPTION, AppearanceCard } from '@/components/appearance-card'
import { SectionedPage } from '@/components/sectioned-page'
import { APPEARANCE_SECTION } from '@/components/shell/nav'

const SECTIONS = [APPEARANCE_SECTION]

/** An agent's «تنظیمات پنل»: only the panel's look in this browser (their shop's settings are the bot settings). */
export function SettingsPage() {
  return (
    <SectionedPage sections={SECTIONS} width="narrow" header={() => ({ title: 'تنظیمات پنل', description: APPEARANCE_DESCRIPTION })}>
      {{ appearance: <AppearanceCard /> }}
    </SectionedPage>
  )
}
