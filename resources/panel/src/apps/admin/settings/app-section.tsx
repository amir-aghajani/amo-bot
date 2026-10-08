import { useConfigGroup } from '@/apps/admin/settings/use-config-group'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { api } from '@/lib/api'
import type { ConfigAppRequest, ConfigAppSettings, ConfigSettingsResponse } from '@/lib/api-types'
import { queryKeys } from '@/lib/query-keys'

/** The shop's name and public address (its sub-folder in it), the zone its times are shown in, and the debug switch. */
export function AppSection({ settings, meta, disabled }: { settings: ConfigAppSettings; meta: ConfigSettingsResponse['meta']; disabled: boolean }) {
  // The name is the brand's and every page title's, the zone every date's: the panel reads them again (lib/app-info).
  const group = useConfigGroup(settings satisfies ConfigAppRequest, { save: (values) => api.put('/settings/config/app', values), saved: 'تنظیمات برنامه ذخیره شد', invalidates: [queryKeys.app] })
  const { values, set, error } = group

  return (
    <SectionCard form={group} title="برنامه" description="نام و آدرس عمومی سایت و منطقه زمانی." disabled={disabled}>
      <div className="grid gap-4 sm:grid-cols-[1fr_1.4fr]">
        <Field id="app_name" label="نام برنامه" error={error('name')} hint="در عنوان صفحه‌ها و پیام‌های ربات.">
          <Input value={values.name} onChange={(e) => set('name', e.target.value)} />
        </Field>
        <Field id="app_url" label="آدرس سایت" error={error('url')} hint="بدون / در انتها، با زیرپوشه اگر برنامه در زیرپوشه نصب شده؛ Webhook تلگرام به https نیاز دارد.">
          <Input dir="ltr" inputMode="url" value={values.url} onChange={(e) => set('url', e.target.value)} placeholder="https://shop.example.com" />
        </Field>
      </div>

      <Field
        id="app_timezone"
        label="منطقه زمانی"
        error={error('timezone')}
        hint="تاریخ و ساعت‌هایی که پنل‌ها و ربات نشان می‌دهند، روزهای داشبورد و لاگ‌ها به این منطقه زمانی هستند؛ تغییرش زمان‌های ثبت‌شده را جابه‌جا نمی‌کند."
      >
        {(control) => (
          <Select value={values.timezone} onValueChange={(value) => set('timezone', value)}>
            <SelectTrigger {...control} className="w-full sm:max-w-sm">
              <SelectValue placeholder="انتخاب منطقه زمانی" />
            </SelectTrigger>
            <SelectContent className="max-h-80">
              {meta.timezones.map((zone) => (
                <SelectItem key={zone.id} value={zone.id}>
                  {zone.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </Field>

      <SwitchRow
        label="حالت Debug"
        hint="روشن باشد، جواب هر خطای پیش‌بینی‌نشده سرور جزئیات فنی دارد: نوع خطا، متن آن و فایل‌ها و خط‌های کدی که به خطا رسید. این جزئیات فقط به درخواستی می‌رسد که مستقیم از خود همین سرور (localhost) فرستاده شود؛ مرورگر شما و هیچ بازدیدکننده دیگری آن را نمی‌بیند. فقط برای عیب‌یابی روشن کنید."
        checked={values.debug}
        onCheckedChange={(checked) => set('debug', checked)}
        error={error('debug')}
      />
    </SectionCard>
  )
}
