import { useEffect, useState } from 'react'
import { Phone } from 'lucide-react'
import { keys, queryClient } from '@/apps/app/lib/query'
import { result } from '@/apps/app/telegram/haptics'
import { openTelegramLink, supports, when } from '@/apps/app/telegram/webapp'
import { Row, Section } from '@/apps/app/ui/section'
import { Notice } from '@/apps/app/ui/states'

/*
 * The customer's phone number on «حساب من»: shown once the bot has it; while the shop's bot asks every customer for
 * theirs and it has none, «ثبت شماره تلفن» — Telegram's own contact request (Bot API 6.9), which sends the customer's
 * contact to the bot; the bot records it, and the account is read again every few seconds until it shows. An older
 * client opens the bot instead, where its own button asks for it.
 */

/** How often, and how many times, the account is read again after the contact went to the bot. */
const POLL_MS = 3_000
const POLLS = 10

interface PhoneSectionProps {
  phone: string | null
  /** The shop's bot asks every customer for their number (`GET /` shop.phone_required). */
  required: boolean
  /** The shop's bot, for a client that cannot send the contact from here. */
  botUrl: string | null
}

export function PhoneSection({ phone, required, botUrl }: PhoneSectionProps) {
  const [shared, setShared] = useState<boolean | null>(null)

  useEffect(() => {
    if (shared !== true || phone !== null) return
    let polls = 0
    const timer = setInterval(() => {
      polls += 1
      void queryClient.invalidateQueries({ queryKey: keys.me, exact: true })
      if (polls >= POLLS) clearInterval(timer)
    }, POLL_MS)

    return () => clearInterval(timer)
  }, [shared, phone])

  if (phone !== null) {
    return (
      <Section>
        <Row icon={Phone} tint="#30b350" title="شماره تلفن" value={<span className="ltr">{phone}</span>} />
      </Section>
    )
  }
  if (!required) return null

  const direct = supports('6.9')
  const share = () => {
    if (!direct) {
      if (botUrl !== null) openTelegramLink(botUrl)
      return
    }
    when('6.9', (app) =>
      app.requestContact?.((sent) => {
        result(sent ? 'success' : 'warning')
        setShared(sent)
      }),
    )
  }

  return (
    <Section footer={direct ? 'شماره شما از تلگرام برای ربات فروشگاه فرستاده می‌شود.' : 'در ربات فروشگاه، دکمه ارسال شماره را بزنید.'}>
      <Row icon={Phone} tint="#30b350" title="ثبت شماره تلفن" subtitle="ربات فروشگاه شماره تلفن شما را لازم دارد" onClick={share} disabled={!direct && botUrl === null} />
      {shared !== null && (
        <div className="px-4 pt-3 pb-4">
          <Notice tone={shared ? 'success' : 'warning'}>{shared ? 'شماره شما برای ربات فرستاده شد؛ چند لحظه بعد اینجا ثبت می‌شود.' : 'شماره فرستاده نشد؛ هر وقت خواستید دوباره بزنید.'}</Notice>
        </div>
      )}
    </Section>
  )
}
