import { Info, TriangleAlert } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Callout } from '@/components/callout'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { api } from '@/lib/api'
import type { BotPaymentsSettingsRequest, BotSettingsData } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/**
 * «پرداخت کارت به کارت», in every shop: how a card-to-card payment is taken — the card's message protected from
 * forwarding and screenshots, the customer sent to support's private chat instead of being shown a card (refused under
 * its switch while the bot has no support contact, and warned of once the contact was emptied since), one receipt at a
 * time — and where receipts are reviewed.
 */
export function PaymentsSection({ settings }: { settings: BotSettingsData }) {
  const group = useBotSettingsGroup(
    { card_protect: settings.card_protect, card_to_support: settings.card_to_support, receipt_single: settings.receipt_single } satisfies BotPaymentsSettingsRequest,
    (values) => api.put('/bot/settings/payments', values),
    // A card-to-card method says whether its card is given in support's chat (`to_support`).
    { invalidates: [queryKeys.paymentMethods] },
  )
  const { values, set, error } = group
  const noContact = settings.card_to_support && settings.support_contact.trim() === ''

  return (
    <SectionCard form={group} title="پرداخت کارت به کارت" description="پیام شماره کارت در ربات، و رسیدهایی که مشتری‌ها برای پرداخت کارت به کارت می‌فرستند.">
      <SwitchRow
        label="جلوگیری از اسکرین‌شات و فوروارد"
        hint="پیام شماره کارت محافظت‌شده فرستاده می‌شود: فوروارد، ذخیره و کپی متنش بسته است و اپ‌های موبایل تلگرام از آن اسکرین‌شات نمی‌گیرند. دکمه‌های کپی شماره کارت و مبلغ همچنان کار می‌کنند."
        checked={values.card_protect}
        onCheckedChange={(checked) => set('card_protect', checked)}
        error={error('card_protect')}
      />
      <SwitchRow
        label="هدایت پرداخت کارت به کارت به پیوی پشتیبانی"
        hint="شماره کارتی نشان داده نمی‌شود: مشتری شماره سفارش و مبلغ را می‌بیند و به پیوی پشتیبانی (راه ارتباط با پشتیبانی در بخش «عمومی») فرستاده می‌شود تا کارت را بگیرد و رسید را همان‌جا بفرستد؛ یادآوری پرداخت هم او را به همان‌جا می‌فرستد. کارتی از کارت‌های روش برای پرداخت انتخاب نمی‌شود (سقف روزانه‌شان پر نمی‌شود) و در صفحه پرداخت‌ها به جای کارت «کارت در پیوی پشتیبانی داده شد» می‌آید. پرداخت در انتظار می‌ماند تا پشتیبانی در صفحه پرداخت‌ها تاییدش کند و خودبه‌خود لغو نمی‌شود، حتی بعد از خاموش کردن این گزینه؛ پرداختی را که انجام نشد همان‌جا لغو کنید. اگر راه ارتباط با پشتیبانی پاک شود، شماره کارت مثل قبل نشان داده می‌شود."
        checked={values.card_to_support}
        onCheckedChange={(checked) => set('card_to_support', checked)}
        error={error('card_to_support')}
      />
      {noContact && (
        <Callout tone="warning" icon={TriangleAlert}>
          راه ارتباط با پشتیبانی در بخش «عمومی» خالی است؛ تا آن را ننویسید، ربات و سایت شماره کارت را مثل قبل نشان می‌دهند.
        </Callout>
      )}
      <SwitchRow
        label="هر مشتری فقط یک رسید در انتظار بررسی"
        hint="تا رسید قبلی مشتری بررسی نشده، رسید دیگری از او پذیرفته نمی‌شود و پرداخت کارت به کارت تازه‌ای شروع نمی‌کند؛ ربات و سایت به او می‌گویند رسید قبلی‌اش در حال بررسی پشتیبانی است. پرداخت با کیف پول آزاد است."
        checked={values.receipt_single}
        onCheckedChange={(checked) => set('receipt_single', checked)}
        error={error('receipt_single')}
      />
      <Callout tone="info" icon={Info}>
        <ul className="grid list-disc gap-1.5 ps-4">
          <li>زیر پیام شماره کارت، دکمه‌های کپی شماره کارت و مبلغ می‌آید؛ مبلغ با رقم انگلیسی و بدون جداکننده کپی می‌شود، همان‌طور که اپلیکیشن بانک می‌پذیرد.</li>
          <li>
            رسیدها در{' '}
            <TextLink to="/payments?status=awaiting_review" inline className="font-medium">
              پرداخت‌ها
            </TextLink>{' '}
            بررسی می‌شوند؛ برای رد کردن چند رسید در انتظار با هم، همان‌جا انتخابشان کنید.
          </li>
        </ul>
      </Callout>
    </SectionCard>
  )
}
