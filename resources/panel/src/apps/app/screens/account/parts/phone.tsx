import { useEffect, useState } from 'react'
import { Phone } from 'lucide-react'
import { keys, queryClient } from '@/apps/app/lib/query'
import { result } from '@/apps/app/telegram/haptics'
import { openTelegramLink, supports, when } from '@/apps/app/telegram/webapp'
import { Row, Section } from '@/apps/app/ui/section'
import { Notice } from '@/apps/app/ui/states'
import type { PhoneMoment } from '@/lib/api-types'

/*
 * The customer's phone number on «حساب من»: shown once the bot has it; while the shop's bot asks for theirs — before
 * everything, or only at the moments the shop chose — and it has none, «ثبت شماره تلفن» — Telegram's own contact request
 * (Bot API 6.9), which sends the customer's contact to the bot; the bot records it, and the account is read again every
 * few seconds until it shows. An older client opens the bot instead, where its own button asks for it. A customer
 * support excused from the number, or a bot admin, is never asked it.
 */

/** How often, and how many times, the account is read again after the contact went to the bot. */
const POLL_MS = 3_000
const POLLS = 10

/** Each moment in the subtitle's words, in the order the shop lists them. */
const MOMENT_WORDS: Record<PhoneMoment, string> = {
  purchase: 'خرید',
  renewal: 'تمدید',
  test: 'اکانت تست',
  card: 'پرداخت کارت به کارت',
}

/** «برای خرید و تمدید لازم است»: what the number is needed for, while the shop asks it only at `moments`. */
function momentsWords(moments: PhoneMoment[]): string {
  const words = moments.map((moment) => MOMENT_WORDS[moment])
  const listed = words.length > 1 ? `${words.slice(0, -1).join('، ')} و ${words[words.length - 1]}` : (words[0] ?? '')

  return `برای ${listed} لازم است`
}

interface PhoneSectionProps {
  phone: string | null
  /** The shop's bot asks every customer for their number before anything else (`GET /` shop.phone_required). */
  required: boolean
  /** Or only at these moments (`GET /` shop.phone_moments). */
  moments: PhoneMoment[]
  /** Never asked the number: support excused them, or they are a bot admin (`GET /me` customer.phone_exempt). */
  exempt: boolean
  /** Only Iranian numbers (+98) count (`GET /` shop.phone_iran_only): another country's on file is asked again. */
  iranOnly: boolean
  /** The shop's bot, for a client that cannot send the contact from here. */
  botUrl: string | null
}

export function PhoneSection({ phone, required, moments, exempt, iranOnly, botUrl }: PhoneSectionProps) {
  const [shared, setShared] = useState<boolean | null>(null)
  const asked = !exempt && (required || moments.length > 0)
  // A number the shop does not take now: another country's, while Iranian ones alone count.
  const foreign = phone !== null && iranOnly && asked && !phone.startsWith('+98')
  const taken = phone !== null && !foreign

  useEffect(() => {
    if (shared !== true || taken) return
    let polls = 0
    const timer = setInterval(() => {
      polls += 1
      void queryClient.invalidateQueries({ queryKey: keys.me, exact: true })
      if (polls >= POLLS) clearInterval(timer)
    }, POLL_MS)

    return () => clearInterval(timer)
  }, [shared, taken])

  if (phone !== null && !foreign) {
    return (
      <Section>
        <Row icon={Phone} tint="#30b350" title="شماره تلفن" value={<span className="ltr">{phone}</span>} />
      </Section>
    )
  }
  if (!asked) return null

  const direct = supports('6.9')
  const share = () => {
    if (!direct) {
      if (botUrl !== null) openTelegramLink(botUrl)
      return
    }
    when('6.9', (app) =>
      app.requestContact?.((sent) => {
        result(sent && !foreign ? 'success' : 'warning')
        setShared(sent)
      }),
    )
  }
  const need = required ? 'ربات فروشگاه شماره تلفن شما را لازم دارد' : momentsWords(moments)

  return (
    <Section footer={direct ? 'شماره شما از تلگرام برای ربات فروشگاه فرستاده می‌شود.' : 'در ربات فروشگاه، دکمه ارسال شماره را بزنید.'}>
      {foreign && (
        <div className="px-4 pt-3">
          <Notice tone="warning">
            این فروشگاه فقط شماره‌های ایران (<span className="ltr">+98</span>) را می‌پذیرد و شماره <span className="ltr">{phone}</span> پذیرفته نیست. اگر شماره ایرانی ندارید، با پشتیبانی تماس بگیرید.
          </Notice>
        </div>
      )}
      <Row
        icon={Phone}
        tint="#30b350"
        title={foreign ? 'ثبت شماره ایرانی' : 'ثبت شماره تلفن'}
        subtitle={
          // The +98 rule: in the notice above for a number on file, here for none.
          iranOnly && !foreign ? (
            <>
              {need} — فقط شماره ایران (<span className="ltr">+98</span>)
            </>
          ) : (
            need
          )
        }
        onClick={share}
        disabled={!direct && botUrl === null}
      />
      {shared !== null && (
        <div className="px-4 pt-3 pb-4">
          {/* Telegram sends their Telegram account's number: the bot takes it only if it is Iranian. */}
          {shared && foreign ? (
            <Notice tone="warning">اگر شماره تلگرامتان ایرانی است چند لحظه بعد اینجا ثبت می‌شود؛ وگرنه با پشتیبانی تماس بگیرید.</Notice>
          ) : (
            <Notice tone={shared ? 'success' : 'warning'}>{shared ? 'شماره شما برای ربات فرستاده شد؛ چند لحظه بعد اینجا ثبت می‌شود.' : 'شماره فرستاده نشد؛ هر وقت خواستید دوباره بزنید.'}</Notice>
          )}
        </div>
      )}
    </Section>
  )
}
