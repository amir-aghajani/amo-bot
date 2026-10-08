import { useMutation } from '@tanstack/react-query'
import { Copy, RefreshCw } from 'lucide-react'
import { useConfigGroup } from '@/apps/admin/settings/use-config-group'
import { Field } from '@/components/field'
import { IconButton } from '@/components/icon-button'
import { SecretField } from '@/components/secret-field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { api } from '@/lib/api'
import type { ConfigAdvancedRequest, ConfigAdvancedSettings, ConfigSettingsResponse, CronUrlResponse } from '@/lib/api-types'
import { copyText } from '@/lib/clipboard'
import { randomToken } from '@/lib/random'

/** The group as its form holds it: the request whole, the numbers as typed, the token blank until one is typed. */
type Draft = Required<ConfigAdvancedRequest> & { session_lifetime: string; http_timeout: string }

/** The log, the admin's session, the timeout of outgoing requests, and the cron that runs from the web. */
export function AdvancedSection({ settings, meta, disabled }: { settings: ConfigAdvancedSettings; meta: ConfigSettingsResponse['meta']; disabled: boolean }) {
  const group = useConfigGroup<Draft>(
    {
      log_level: settings.log_level,
      session_lifetime: String(settings.session_lifetime),
      session_secure_cookie: settings.session_secure_cookie,
      http_timeout: String(settings.http_timeout),
      cron_token: '',
      clear_cron_token: false,
    },
    // The token goes only when typed: left blank, the stored one stays.
    { save: (values) => api.put('/settings/config/advanced', { ...values, cron_token: values.cron_token || undefined }), saved: 'تنظیمات پیشرفته ذخیره شد' },
  )
  const { values, set, error } = group

  // The screen shows the address with its token masked; the whole one is asked for when it is copied.
  const copyCronUrl = useMutation({
    mutationFn: () => api.get<CronUrlResponse>('/settings/config/cron-url'),
    onSuccess: ({ url }) => {
      if (url) void copyText(url, 'آدرس Cron کپی شد')
    },
  })

  return (
    <SectionCard form={group} title="پیشرفته" description="لاگ، Session پنل‌ها، تایم‌اوت درخواست‌های خروجی و اجرای Cron از وب." disabled={disabled}>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field id="adv_log_level" label="سطح لاگ" error={error('log_level')}>
          {(control) => (
            <Select value={values.log_level} onValueChange={(value) => set('log_level', value)}>
              <SelectTrigger {...control} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {meta.log_levels.map((level) => (
                  <SelectItem key={level} value={level}>
                    <span dir="ltr">{level}</span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
        <Field id="adv_session_lifetime" label="طول Session پنل‌ها (دقیقه)" error={error('session_lifetime')} hint="بعد از این مدت بی‌کاری باید دوباره وارد شد؛ در پنل شما و پنل نماینده‌ها.">
          <Input dir="ltr" inputMode="numeric" value={values.session_lifetime} onChange={(e) => set('session_lifetime', e.target.value)} />
        </Field>
        <Field id="adv_http_timeout" label="تایم‌اوت درخواست‌های خروجی (ثانیه)" error={error('http_timeout')}>
          <Input dir="ltr" inputMode="numeric" value={values.http_timeout} onChange={(e) => set('http_timeout', e.target.value)} />
        </Field>
      </div>

      <SwitchRow
        label="کوکی Session همیشه امن"
        hint="روی https کوکی خودکار امن می‌شود. فقط وقتی روشن کنید که سایت پشت پروکسی (مثلا Cloudflare) است و PHP درخواست را http می‌بیند؛ روی http واقعی ورود به پنل غیرممکن می‌شود."
        checked={values.session_secure_cookie}
        onCheckedChange={(checked) => set('session_secure_cookie', checked)}
        error={error('session_secure_cookie')}
      />

      <div className="grid gap-3">
        <SecretField
          id="adv_cron_token"
          label="توکن Cron"
          optional
          stored={settings.cron_token}
          value={values.cron_token}
          onChange={(value) => set('cron_token', value)}
          clear={values.clear_cron_token}
          onClear={(clear) => set('clear_cron_token', clear)}
          error={error('cron_token')}
          hint="کارهای زمان‌بندی‌شده (تمدید خودکار، یادآوری‌ها، همگام‌سازی سرویس‌ها) با صدا زدن آدرس Cron اجرا می‌شوند: توکن بسازید و ذخیره کنید، بعد آدرسی را که این‌جا دیده می‌شود هر دقیقه صدا بزنید — در cPanel از بخش Cron Jobs، یا با یک سرویس Cron بیرونی."
          action={<Button variant="secondary" size="icon" icon={RefreshCw} aria-label="تولید توکن تازه" title="تولید توکن تازه" onClick={() => set('cron_token', randomToken(20))} />}
        />
        {meta.cron_url && (
          <div className="flex items-center gap-2 rounded-lg border border-border bg-fill px-3 py-2">
            <code dir="ltr" className="min-w-0 flex-1 truncate text-start text-footnote">
              {meta.cron_url}
            </code>
            <IconButton icon={Copy} busy={copyCronUrl.isPending} onClick={() => copyCronUrl.mutate()} aria-label="کپی آدرس Cron" className="-me-1 size-7" />
          </div>
        )}
      </div>
    </SectionCard>
  )
}
