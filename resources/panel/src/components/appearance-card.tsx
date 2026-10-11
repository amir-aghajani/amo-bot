import { Moon, Sun } from 'lucide-react'
import { PageTabs, type PageTab } from '@/components/page-tabs'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { useTheme, type Theme } from '@/lib/theme'

const THEMES: PageTab<Theme>[] = [
  { value: 'dark', label: 'تیره', icon: Moon },
  { value: 'light', label: 'روشن', icon: Sun },
]

/** What the «ظاهر» section is, under the page's title in either panel: kept in the browser, not with the shop. */
export const APPEARANCE_DESCRIPTION = 'ظاهر پنل؛ در همین مرورگر ذخیره می‌شود، نه در فروشگاه.'

/** The «ظاهر» settings section of both panels: dark or light, applied at once and kept in this browser (lib/theme). */
export function AppearanceCard() {
  const { theme, choose } = useTheme()

  return (
    <Card>
      <CardHeader className="pb-4">
        <CardHeading>
          <CardTitle className="text-heading">تم</CardTitle>
          <CardDescription>رنگ‌بندی پنل؛ همان لحظه اعمال می‌شود و هر مرورگر انتخاب خودش را نگه می‌دارد.</CardDescription>
        </CardHeading>
      </CardHeader>
      <CardContent className="pb-5">
        <PageTabs as="choice" value={theme} onChange={choose} tabs={THEMES} aria-label="تم پنل" size="sm" className="w-fit" />
      </CardContent>
    </Card>
  )
}
