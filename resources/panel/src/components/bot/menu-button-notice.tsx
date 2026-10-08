import { useQuery } from '@tanstack/react-query'
import { TriangleAlert } from 'lucide-react'
import { Callout } from '@/components/callout'
import { TextLink } from '@/components/text-link'
import { keyboardsQuery } from '@/lib/queries'

interface MenuButtonNoticeProps {
  /** The menu action whose button is missing (MainMenu::ACTIONS): `affiliates`, `agency`. */
  action: string
  /** Its button, as the editor names it. */
  label: string
  /** Where customers would have no way to: «صفحه لینک دعوتشان». */
  screen: string
}

/**
 * A program runs but the bot's menu has no button for it, so customers have no way to its screen. A layout saved before
 * the button existed is the usual reason — the keyboards editor adds it.
 */
export function MenuButtonNotice({ action, label, screen }: MenuButtonNoticeProps) {
  const { data } = useQuery(keyboardsQuery)
  const start = data?.keyboards.find((keyboard) => keyboard.name === 'start')

  if (!start || start.rows.some((row) => row.some((button) => button.action === action))) {
    return null
  }

  return (
    <Callout tone="warning" icon={TriangleAlert}>
      دکمه «{label}» در منوی ربات نیست، پس مشتری‌ها به {screen} راهی ندارند.{' '}
      <TextLink to="/keyboards" inline className="font-medium">
        افزودن دکمه در صفحه کیبوردها
      </TextLink>
    </Callout>
  )
}
