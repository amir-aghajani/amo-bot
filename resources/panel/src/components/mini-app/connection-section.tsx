import { useMutation } from '@tanstack/react-query'
import { Copy, Info, RefreshCw, ShieldAlert } from 'lucide-react'
import { toast } from 'sonner'
import { Callout } from '@/components/callout'
import { Field } from '@/components/field'
import { useApplyMiniApp } from '@/components/mini-app/mini-app-query'
import { useMiniAppGroup } from '@/components/mini-app/use-mini-app-group'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardHeading, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { MiniAppSettings, MiniAppSettingsRequest, MiniAppSettingsResponse } from '@/lib/api-types'
import { copyText } from '@/lib/clipboard'

/** The longest menu button words the server keeps (Store\Services\MiniApps). */
const MENU_BUTTON_TEXT_MAX = 32

/** The card's draft: the switch and the bot's menu button, as the request takes them — the words blank for the default. */
type Draft = Required<Pick<MiniAppSettingsRequest, 'enabled' | 'menu_button' | 'menu_button_text'>>

function draftOf(miniApp: MiniAppSettings): Draft {
  return { enabled: miniApp.enabled, menu_button: miniApp.menu_button, menu_button_text: miniApp.menu_button_text ?? '' }
}

interface ConnectionSectionProps {
  miniApp: MiniAppSettings
  /** Where this panel sets the shop's address (the owner's «تنظیمات پنل › برنامه»); none in an agent's — the owner does. */
  appSettings?: string
}

/**
 * «اتصال»: the mini app on or off — held off while APP_URL is not https, which is all Telegram opens — and the bot's menu
 * button that opens it; then its address, to make it the bot's main mini app in @BotFather too.
 */
export function ConnectionSection({ miniApp, appSettings }: ConnectionSectionProps) {
  const group = useMiniAppGroup(miniApp, draftOf, (values) => values)
  const { values, set, error } = group

  return (
    <>
      <SectionCard
        form={group}
        title="مینی‌اپ فروشگاه"
        description="فروشگاه داخل تلگرام: مشتری بدون بیرون رفتن از تلگرام پلن می‌خرد، سرویس‌هایش را می‌بیند و کیف پولش را شارژ می‌کند؛ با همان حسابی که در ربات دارد."
      >
        {!miniApp.secure && <NotSecure on={miniApp.enabled} appSettings={appSettings} />}
        <SwitchRow
          label="مینی‌اپ روشن باشد"
          hint="خاموش که باشد، آدرس مینی‌اپ چیزی نشان نمی‌دهد و ربات دکمه‌ای برای آن ندارد."
          checked={values.enabled}
          onCheckedChange={(checked) => set('enabled', checked)}
          // Only switching it on asks for https, as the server does: one already on may still be switched off.
          held={!miniApp.secure && !miniApp.enabled}
          error={error('enabled')}
        />
        <SwitchRow
          label="دکمه منوی ربات"
          hint="دکمه کنار جای نوشتن پیام در ربات، تا وقتی مینی‌اپ روشن است آن را باز می‌کند؛ خاموش باشد، همان دکمه همیشگی تلگرام می‌ماند."
          checked={values.menu_button}
          onCheckedChange={(checked) => set('menu_button', checked)}
          error={error('menu_button')}
        />
        <Field id="mini_app_menu_button_text" label="متن دکمه منو" optional error={error('menu_button_text')} hint={`خالی بماند، «${miniApp.menu_button_default}» نوشته می‌شود.`}>
          <Input maxLength={MENU_BUTTON_TEXT_MAX} value={values.menu_button_text} onChange={(e) => set('menu_button_text', e.target.value)} placeholder={miniApp.menu_button_default} />
        </Field>
      </SectionCard>
      <AddressCard miniApp={miniApp} />
    </>
  )
}

/**
 * APP_URL is not https, and Telegram opens nothing else: the app is not switched on until it is (one on already stays
 * on, unopened). Where the address is set is the panel's to say — the owner's links its settings (`appSettings`), an
 * agent's says the owner sets it.
 */
function NotSecure({ on, appSettings }: { on: boolean; appSettings?: string }) {
  return (
    <Callout tone="warning" icon={ShieldAlert}>
      تلگرام مینی‌اپ را فقط از آدرس https باز می‌کند و آدرس برنامه (<bdi dir="ltr">APP_URL</bdi>) این فروشگاه https نیست؛ تا https نشود،{' '}
      {on ? 'تلگرام مینی‌اپ را برای مشتری باز نمی‌کند.' : 'مینی‌اپ روشن نمی‌شود.'}{' '}
      {appSettings === undefined ? (
        'آدرس برنامه را مالک فروشگاه تنظیم می‌کند.'
      ) : (
        <>
          آدرس را در{' '}
          <TextLink inline to={appSettings}>
            تنظیمات پنل › برنامه
          </TextLink>{' '}
          با https بنویسید.
        </>
      )}
    </Callout>
  )
}

/** Where the app opens, to copy, the shop's bot, and how to make the app the bot's main one in @BotFather (https only). */
function AddressCard({ miniApp }: { miniApp: MiniAppSettings }) {
  return (
    <Card>
      <CardHeader>
        <CardHeading>
          <CardTitle className="text-heading">آدرس مینی‌اپ</CardTitle>
          <CardDescription>مینی‌اپ روی همین آدرس است و ربات با دکمه‌هایش آن را باز می‌کند؛ بیرون از تلگرام فقط می‌گوید آن را از ربات باز کنید.</CardDescription>
        </CardHeading>
      </CardHeader>

      <CardContent className="grid gap-4">
        <div className="flex items-center gap-2 rounded-lg border border-border bg-fill p-2 ps-3">
          <span dir="ltr" className="min-w-0 flex-1 text-footnote break-all select-all">
            {miniApp.url}
          </span>
          <Button size="sm" variant="secondary" icon={Copy} onClick={() => void copyText(miniApp.url, 'آدرس مینی‌اپ کپی شد')}>
            کپی
          </Button>
        </div>

        {miniApp.bot && (
          <div className="grid gap-0.5">
            <span className="text-footnote text-muted-foreground">ربات فروشگاه</span>
            <TextLink href={miniApp.bot.url} className="w-fit text-body">
              <bdi dir="ltr">@{miniApp.bot.username}</bdi>
            </TextLink>
          </div>
        )}

        {miniApp.secure && miniApp.enabled && miniApp.menu_button && <MenuButtonAgain />}
        {miniApp.secure && <MainAppSteps url={miniApp.url} />}
      </CardContent>
    </Card>
  )
}

/**
 * The bot's menu button set again as the app stands — a save with nothing changed (`PATCH /mini-app` with no field): after
 * the shop moved to another address, or a new token for the bot, the button still opens the old one until then.
 */
function MenuButtonAgain() {
  const applyAnswer = useApplyMiniApp()
  const apply = useMutation({
    mutationFn: () => api.patch('/mini-app', {}),
    onSuccess: (result: MiniAppSettingsResponse) => {
      // A refusal shows as the page's warning, read from the answer.
      applyAnswer(result)
      if (result.menu_button_error === null) toast.success('دکمه منوی ربات دوباره تنظیم شد')
    },
  })

  return (
    <div className="flex flex-wrap items-center justify-between gap-2">
      <span className="text-footnote text-muted-foreground">آدرس برنامه یا توکن ربات عوض شده؟ دکمه منوی ربات را با آدرس فعلی دوباره تنظیم کنید.</span>
      <Button size="sm" variant="secondary" icon={RefreshCw} busy={apply.isPending} onClick={() => apply.mutate()}>
        تنظیم دوباره دکمه منو
      </Button>
    </div>
  )
}

/** What the owner does in @BotFather for the bot's profile to open the app too: the app's address as its Main App URL. */
function MainAppSteps({ url }: { url: string }) {
  return (
    <Callout tone="info" icon={Info}>
      <p className="font-medium">مینی‌اپ اصلی ربات در BotFather</p>
      <p className="mt-0.5 text-muted-foreground">اختیاری: با این کار، پروفایل ربات هم دکمه باز کردن مینی‌اپ را نشان می‌دهد.</p>
      <ol className="mt-1.5 grid list-[persian] gap-1 ps-5">
        <li>
          در تلگرام <bdi dir="ltr">@BotFather</bdi> را باز کنید، ربات فروشگاه را انتخاب کنید و به <bdi dir="ltr">Bot Settings › Configure Mini App</bdi> بروید.
        </li>
        <li>
          مینی‌اپ را روشن کنید و برای <bdi dir="ltr">Main App URL</bdi> همین آدرس را بفرستید: <bdi dir="ltr">{url}</bdi>
        </li>
      </ol>
    </Callout>
  )
}
