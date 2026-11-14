import { useId } from 'react'
import { useQuery } from '@tanstack/react-query'
import { MessageSquareText } from 'lucide-react'
import { toast } from 'sonner'
import { Field } from '@/components/field'
import { FormActions } from '@/components/form-footer'
import { Modal } from '@/components/modal'
import { NoteInput } from '@/components/note-input'
import { SendIcon } from '@/components/send-icon'
import { TextLink } from '@/components/text-link'
import { userLabel } from '@/components/user-identity'
import { api } from '@/lib/api'
import type { Delivery, UserMessageRequest, UserRow } from '@/lib/api-types'
import { botSettingsQuery } from '@/lib/queries'
import { useForm } from '@/lib/use-form'

/** The longest message the server takes (Users\Services\UserActions::MESSAGE_MAX), in UTF-16 units as `.length` counts them. */
const TEXT_MAX = 3500

/** Where the bot settings' «عنوان پیام پشتیبانی» is switched. */
const HEADER_SETTING = '/bot-settings/general'

const BLANK: UserMessageRequest = { text: '' }

interface MessageDialogProps {
  /** The customer the message is for; null when closed. */
  customer: UserRow | null
  onClose: () => void
}

/**
 * «ارسال پیام» on a customer's page (both panels): support writes the customer a message the shop's bot sends them —
 * no need to open their chat with support's own account (`POST /users/{id}/message`). It is kept on their website and
 * mini app either way; what came of it in Telegram, or by email, is said once it went.
 */
export function MessageDialog({ customer, onClose }: MessageDialogProps) {
  return (
    <Modal
      open={customer !== null}
      onClose={onClose}
      typing
      title="ارسال پیام"
      description={customer ? `پیامی از طرف پشتیبانی که ربات فروشگاه برای ${userLabel(customer)} می‌فرستد؛ در وب‌سایت و مینی‌اپ او هم می‌ماند.` : undefined}
    >
      {customer && <MessageForm key={customer.id} customer={customer} onClose={onClose} />}
    </Modal>
  )
}

function MessageForm({ customer, onClose }: { customer: UserRow; onClose: () => void }) {
  const id = useId()
  const { values, set, error, formError, busy, dirty, submit, handleSubmit } = useForm<UserMessageRequest>(BLANK)
  const empty = values.text.trim() === ''

  const send = handleSubmit(async () => {
    const answer = await submit(() => api.post(`/users/${customer.id}/message`, values))
    if (!answer) return
    told(answer.delivery, customer)
    onClose()
  })

  return (
    <form onSubmit={send} noValidate className="grid gap-4">
      {/* Held while it goes: what is typed meanwhile would not go with it. */}
      <Field id={`${id}-text`} label="متن پیام" error={error('text')} hint="متن ساده؛ خط‌ها همان‌طور که می‌نویسید می‌رسند.">
        <NoteInput dir="auto" max={TEXT_MAX} rows={6} value={values.text} onChange={(event) => set('text', event.target.value)} readOnly={busy} placeholder="پیام خود را بنویسید" />
      </Field>

      <Heading />

      <FormActions error={formError} onCancel={onClose} submitLabel="ارسال پیام" icon={SendIcon} busy={busy} disabled={empty || values.text.length > TEXT_MAX} dirty={dirty} />
    </form>
  )
}

/**
 * How the message reads in Telegram: under the bot's text «پیام پشتیبانی به مشتری», or the words alone — as the bot
 * settings' `support_header` says, once read (the panel reads them for other screens too); until then, both said.
 */
function Heading() {
  const header = useQuery(botSettingsQuery).data?.settings.support_header
  const setting = (
    <TextLink to={HEADER_SETTING} inline>
      تنظیمات ربات › عمومی
    </TextLink>
  )

  return (
    <p className="flex items-start gap-2 text-footnote text-muted-foreground">
      <MessageSquareText className="mt-0.5 size-3.5 shrink-0" aria-hidden />
      <span>
        {header === undefined ? (
          <>با «عنوان پیام پشتیبانی» روشن در {setting}، در تلگرام زیر «📩 پیام از طرف پشتیبانی:» می‌رسد؛ خاموش، فقط خود متن.</>
        ) : header ? (
          <>در تلگرام زیر عنوان «📩 پیام از طرف پشتیبانی:» می‌رسد (متن «پیام پشتیبانی به مشتری» در متن‌های ربات؛ عنوان در {setting} خاموش می‌شود).</>
        ) : (
          <>در تلگرام بدون عنوان، فقط خود متن می‌رسد («عنوان پیام پشتیبانی» در {setting} خاموش است).</>
        )}
      </span>
    </p>
  )
}

/** What support is told once the message went, by what came of it in Telegram (or by email); it is on the customer's website either way. */
function told(delivery: Delivery, customer: UserRow) {
  switch (delivery) {
    case 'told':
      toast.success('پیام در تلگرام به کاربر رسید.')
      return
    case 'emailed':
      // Without Telegram the email was the way it goes, not a fallback.
      toast.success(customer.telegram_id === null ? 'پیام به ایمیل کاربر رفت.' : 'کاربر در تلگرام در دسترس نبود؛ پیام به ایمیلش رفت.')
      return
    case 'turned_away':
      toast.warning('کاربر ربات را بلاک کرده؛ پیام در تلگرام به او نرسید و فقط در وب‌سایتش می‌ماند.')
      return
    case 'unreachable':
      // Without Telegram the message was to go by email: its server is what could not be reached.
      toast.warning(customer.telegram_id === null ? 'سرور ایمیل در دسترس نبود و پیام نرسید؛ کمی بعد دوباره بفرستید.' : 'تلگرام در دسترس نبود و پیام نرسید؛ کمی بعد دوباره بفرستید.')
      return
    case 'refused':
      toast.error('تلگرام پیام را نپذیرفت.')
      return
    case 'no_telegram':
      // An email they have did not go: the shop's email is not set up.
      toast.info(customer.email ? 'کاربر تلگرام ندارد و ایمیل هم برایش نرفت؛ پیام فقط در وب‌سایتش می‌ماند.' : 'کاربر تلگرام و ایمیل ندارد؛ پیام فقط در وب‌سایتش می‌ماند.')
  }
}
