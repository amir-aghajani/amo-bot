import { useQuery } from '@tanstack/react-query'
import { BotMessageSquare, CircleCheck, RefreshCw } from 'lucide-react'
import { useConfigGroup } from '@/apps/admin/settings/use-config-group'
import { Callout } from '@/components/callout'
import { Disclosure } from '@/components/disclosure'
import { Field } from '@/components/field'
import { SecretField } from '@/components/secret-field'
import { SecretInput } from '@/components/secret-input'
import { SectionCard } from '@/components/section-card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotMode, ConfigSettingsResponse, ConfigTelegramRequest, ConfigTelegramSettings } from '@/lib/api-types'
import { systemQuery } from '@/lib/queries'
import { randomToken } from '@/lib/random'
import { trimmed } from '@/lib/use-form'
import { useProbe } from '@/lib/use-probe'
import { originOf } from '@/lib/utils'

/**
 * When what is saved here reaches the main bot, by how the bots run (config.php is read once per process): a webhook's
 * next request reads it — a new bot or secret needs the webhook registered again —; a developer's poller (bot:poll)
 * reads it once it starts again.
 */
const AFTER_SAVE: Record<BotMode, string> = {
  webhook: 'ربات روی Webhook است و مقادیر تازه از پیام بعدی به کار می‌روند؛ اگر توکن ربات دیگری را گذاشتید یا رمز Webhook را عوض کردید، Webhook را پایین‌تر دوباره ثبت کنید.',
  polling: 'ربات با bot:poll کار می‌کند و مقادیر تازه را وقتی دوباره راه بیفتد می‌خواند؛ روی هاست، Webhook را پایین‌تر ثبت کنید تا از پیام بعدی به کار بروند.',
  offline: 'ربات الان پیامی نمی‌گیرد: Webhook را پایین‌تر ثبت کنید.',
}

/** A kept token whose Bot API address moved elsewhere: said before the save, in the server's words (ConfigSettings::TOKEN_MOVED). */
const TOKEN_MOVED = 'آدرس API تلگرام عوض شده است؛ توکن ربات اصلی را دوباره وارد کنید: توکن ذخیره‌شده به آدرس دیگری فرستاده نمی‌شود.'

/** The group as its form holds it: the request whole, the timeout as typed, the secrets and the owner's password blank until one is typed. */
type Draft = Required<ConfigTelegramRequest> & { poll_timeout: string }

/** An address's origin — what a token is bound to, and every bot's token goes to —; null while it is no address. */
function originOrNull(address: string): string | null {
  try {
    return new URL(address.trim()).origin
  } catch {
    return null
  }
}

/**
 * The main bot — named so, whichever shop is open: an agent's bot has its own settings —: its BotFather token (checked
 * with Telegram before saving; a kept one said to be typed again while the Bot API's address moves away from it, before
 * the server refuses the save), its @username, and the rarely changed rest — the Bot API's address among it, every
 * bot's token going there: moved to another origin, the save asks the owner's current password with it.
 */
export function TelegramSection({ settings, meta, disabled }: { settings: ConfigTelegramSettings; meta: ConfigSettingsResponse['meta']; disabled: boolean }) {
  const mode = useQuery(systemQuery).data?.system.main_bot.mode
  const group = useConfigGroup<Draft>(
    {
      token: '',
      clear_token: false,
      username: settings.username,
      api_url: settings.api_url,
      current_password: '',
      poll_timeout: String(settings.poll_timeout),
      webhook_secret: '',
      clear_webhook_secret: false,
    },
    {
      // A secret goes only when typed: left blank, the stored one stays. The owner's password, only with a move.
      save: (values) =>
        api.put('/settings/config/telegram', {
          ...values,
          token: values.token || undefined,
          webhook_secret: values.webhook_secret || undefined,
          current_password: movesBotApi(values.api_url) ? values.current_password : undefined,
        }),
      saved: 'تنظیمات ربات ذخیره شد',
      // The owner's password only proves it is them — one a password manager filled in is nothing unsaved.
      reads: (values) => trimmed({ ...values, current_password: '' }),
    },
  )
  const { values, set, error, setFormError, busy } = group
  // The token typed, else the stored one.
  const test = useProbe(() => api.post('/settings/config/telegram/test', { token: values.token || undefined }))
  // A kept token goes only to the Bot API address it was saved for: one moved to another origin is typed again or cleared.
  const tokenLeftBehind = settings.token.set && values.token === '' && !values.clear_token && originOf(values.api_url) !== originOf(settings.api_url)
  // Every bot's token goes to the Bot API's address: moved to another origin, the owner's password is asked with the save.
  function movesBotApi(address: string): boolean {
    const to = originOrNull(address)
    return to !== null && to !== originOrNull(settings.api_url)
  }
  const moving = movesBotApi(values.api_url)

  const check = async () => {
    setFormError(null)
    const data = await test.run()
    if (data && data.bot.username !== values.username) set('username', data.bot.username)
  }

  // What the check said was about the token typed: thrown away with it.
  const revert = () => {
    group.revert()
    test.clear()
  }

  return (
    <SectionCard
      form={{ ...group, revert }}
      title="ربات اصلی"
      description={mode ? `توکن BotFather و نام کاربری ربات اصلی فروشگاه. ${AFTER_SAVE[mode]}` : 'توکن BotFather و نام کاربری ربات اصلی فروشگاه.'}
      disabled={disabled}
      actions={
        <Button variant="secondary" size="sm" icon={BotMessageSquare} busy={test.busy} disabled={busy || (!values.token && !settings.token.set)} onClick={() => void check()}>
          بررسی توکن
        </Button>
      }
    >
      <div className="grid gap-4 sm:grid-cols-[1.4fr_1fr]">
        <SecretField
          id="telegram_token"
          label="توکن ربات اصلی"
          stored={settings.token}
          value={values.token}
          onChange={(value) => {
            set('token', value)
            test.clear()
          }}
          clear={values.clear_token}
          onClear={(clear) => set('clear_token', clear)}
          error={test.failure ? (test.failure.field('token') ?? test.failure.message) : error('token')}
          hint={tokenLeftBehind ? TOKEN_MOVED : 'از BotFather با دستور /newbot یا /token بگیرید.'}
        />
        <Field id="telegram_username" label="نام کاربری ربات اصلی" optional error={error('username')} hint="بدون @؛ با «بررسی توکن» به‌طور خودکار پر می‌شود.">
          <Input dir="ltr" autoComplete="off" spellCheck={false} value={values.username} onChange={(e) => set('username', e.target.value.replace(/^@/, ''))} placeholder="my_shop_bot" />
        </Field>
      </div>

      {test.result && (
        <Callout tone="success" icon={CircleCheck}>
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span className="font-medium">ربات شناسایی شد:</span>
            <span>{test.result.bot.name}</span>
            <bdi dir="ltr" className="text-footnote text-muted-foreground">
              @{test.result.bot.username}
            </bdi>
            <bdi dir="ltr" className="text-footnote text-muted-foreground">
              id {test.result.bot.id}
            </bdi>
          </span>
        </Callout>
      )}

      <Disclosure label="تنظیمات پیشرفته">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id="telegram_api_url"
            label="آدرس API تلگرام"
            error={error('api_url')}
            hint="برای سرور Bot API محلی یا آینه تغییر دهید؛ توکن همه ربات‌ها، ربات‌های نماینده‌ها هم، به همین آدرس فرستاده می‌شود."
          >
            <Input dir="ltr" inputMode="url" value={values.api_url} onChange={(e) => set('api_url', e.target.value)} />
          </Field>
          <Field id="telegram_poll_timeout" label="تایم‌اوت long polling (ثانیه)" error={error('poll_timeout')} hint="فقط در حالت polling استفاده می‌شود.">
            <Input dir="ltr" inputMode="numeric" value={values.poll_timeout} onChange={(e) => set('poll_timeout', e.target.value)} />
          </Field>
        </div>
        {moving && (
          <Field
            id="telegram_current_password"
            label="رمز عبور فعلی پنل"
            error={error('current_password')}
            hint="آدرس API تلگرام به جای دیگری می‌رود و از این به بعد توکن همه ربات‌ها، ربات‌های نماینده‌ها هم، به آن فرستاده می‌شود؛ برای همین رمز عبور ورود به پنل خواسته می‌شود."
          >
            <SecretInput value={values.current_password} onChange={(e) => set('current_password', e.target.value)} autoComplete="current-password" />
          </Field>
        )}
        <SecretField
          id="telegram_webhook_secret"
          label="رمز Webhook"
          optional
          stored={settings.webhook_secret}
          value={values.webhook_secret}
          onChange={(value) => set('webhook_secret', value)}
          clear={values.clear_webhook_secret}
          onClear={(clear) => set('clear_webhook_secret', clear)}
          error={error('webhook_secret')}
          hint={
            <>
              بخشی از آدرس Webhook است: <bdi dir="ltr">{meta.webhook_url}</bdi>. بعد از تغییر، Webhook را در کارت «دریافت پیام‌ها» دوباره ثبت کنید.
            </>
          }
          action={<Button variant="secondary" size="icon" icon={RefreshCw} aria-label="تولید رمز تازه" title="تولید رمز تازه" onClick={() => set('webhook_secret', randomToken(24))} />}
        />
      </Disclosure>
    </SectionCard>
  )
}
