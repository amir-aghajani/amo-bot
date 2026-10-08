import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { ApiAddressCard } from '@/components/website/api-address-card'
import { useWebsiteGroup } from '@/components/website/use-website-group'
import type { Website, WebsiteRequest } from '@/lib/api-types'

/** The card's draft: the request's switch and address, the other origins as typed — one a line. */
type Draft = Required<Pick<WebsiteRequest, 'enabled' | 'url'>> & { origins: string }

function draftOf(website: Website): Draft {
  return { enabled: website.enabled, url: website.url ?? '', origins: website.origins.join('\n') }
}

/** The origins typed, as the list the PATCH takes: one a line — or apart by commas or spaces, as the server reads typed text —, blanks dropped. */
function originsOf(typed: string): string[] {
  return typed.split(/[\s,،]+/).filter((origin) => origin !== '')
}

/** «اتصال»: the website on or off and the addresses its pages call the API from — then the API's own address, to hand over. */
export function ConnectionSection({ website }: { website: Website }) {
  const group = useWebsiteGroup(website, draftOf, (values) => ({ enabled: values.enabled, url: values.url, origins: originsOf(values.origins) }))
  const { values, set, error } = group

  return (
    <>
      <SectionCard form={group} title="وب‌سایت فروشگاه" description="روشن و خاموش کردن API وب‌سایت، و آدرس‌هایی که اجازه دارند از مرورگر بازدیدکننده به آن درخواست بفرستند.">
        <SwitchRow
          label="API وب‌سایت روشن باشد"
          hint="خاموش که باشد، هر درخواستی به آدرس API جواب «این فروشگاه وب‌سایت فعالی ندارد» می‌گیرد."
          checked={values.enabled}
          onCheckedChange={(checked) => set('enabled', checked)}
          error={error('enabled')}
        />
        <Field id="website_url" label="آدرس وب‌سایت" error={error('url')} hint="Origin این آدرس می‌تواند از مرورگر بازدیدکننده به API درخواست بفرستد. برای روشن کردن API لازم است.">
          <Input dir="ltr" inputMode="url" autoComplete="off" spellCheck={false} value={values.url} onChange={(e) => set('url', e.target.value)} placeholder="https://example.com" />
        </Field>
        <Field
          id="website_origins"
          label="Originهای مجاز دیگر"
          optional
          error={error('origins')}
          hint={
            <>
              هر Origin در یک خط؛ مثلا <bdi dir="ltr">http://localhost:3000</bdi> وقتی سایت در حال ساخت است. حداکثر 10 Origin.
            </>
          }
        >
          <Textarea dir="ltr" autoComplete="off" spellCheck={false} value={values.origins} onChange={(e) => set('origins', e.target.value)} placeholder="http://localhost:3000" />
        </Field>
      </SectionCard>
      <ApiAddressCard website={website} />
    </>
  )
}
