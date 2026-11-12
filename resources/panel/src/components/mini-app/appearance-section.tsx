import { Info, X } from 'lucide-react'
import { Callout } from '@/components/callout'
import { Field } from '@/components/field'
import { LogoCard, TELEGRAM_BLUE } from '@/components/mini-app/logo-card'
import { useMiniAppGroup } from '@/components/mini-app/use-mini-app-group'
import { NoteInput } from '@/components/note-input'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { TextLink } from '@/components/text-link'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { MiniAppSettings, MiniAppSettingsRequest } from '@/lib/api-types'

/** The longest title and announcement the server keeps (Store\Services\MiniApps). */
const TITLE_MAX = 64
const ANNOUNCEMENT_MAX = 500

/** A colour as the server keeps it: #rrggbb. */
const HEX = /^#[0-9a-f]{6}$/i

/** «ظاهر»: how the app looks — its name, colour and full screen —, its logo, the shop's announcement in it, and its reviews. */
export function AppearanceSection({ miniApp }: { miniApp: MiniAppSettings }) {
  return (
    <>
      <LookCard miniApp={miniApp} />
      <LogoCard miniApp={miniApp} />
      <AnnouncementCard miniApp={miniApp} />
      <ReviewsCard miniApp={miniApp} />
    </>
  )
}

/** The look card's draft: the request's fields — a blank name or colour for the shop's and Telegram's. */
type LookDraft = Required<Pick<MiniAppSettingsRequest, 'title' | 'accent_color' | 'fullscreen'>>

function lookDraftOf(miniApp: MiniAppSettings): LookDraft {
  return { title: miniApp.title ?? '', accent_color: miniApp.accent_color ?? '', fullscreen: miniApp.fullscreen }
}

/** The app's name (the shop's while blank), its colour (Telegram's while blank) and whether it opens full screen. */
function LookCard({ miniApp }: { miniApp: MiniAppSettings }) {
  // The colour as the server keeps it, in small letters: «#2481CC» typed again is no change.
  const group = useMiniAppGroup(miniApp, lookDraftOf, (values) => ({ ...values, accent_color: values.accent_color.trim().toLowerCase() }))
  const { values, set, error } = group

  return (
    <SectionCard form={group} title="ظاهر مینی‌اپ" description="نام و رنگی که مشتری در مینی‌اپ می‌بیند؛ بقیه رنگ‌ها از تم تلگرام خود مشتری می‌آیند، روشن یا تیره.">
      <Field id="mini_app_title" label="نام مینی‌اپ" optional error={error('title')} hint="بالای مینی‌اپ نشان داده می‌شود؛ خالی بماند، نام فروشگاه.">
        <Input maxLength={TITLE_MAX} value={values.title} onChange={(e) => set('title', e.target.value)} placeholder={miniApp.shop_name} />
      </Field>
      <Field id="mini_app_accent_color" label="رنگ اصلی" optional error={error('accent_color')} hint="رنگ دکمه‌ها و بخش‌های برجسته؛ خالی بماند، رنگ دکمه‌های تلگرام هر مشتری.">
        {(control) => (
          <div className="flex flex-wrap items-center gap-2">
            <input
              type="color"
              aria-label="انتخاب رنگ اصلی"
              value={HEX.test(values.accent_color.trim()) ? values.accent_color.trim().toLowerCase() : TELEGRAM_BLUE}
              onChange={(e) => set('accent_color', e.target.value)}
              className="h-8 w-10 shrink-0 cursor-pointer rounded-md border border-border bg-card p-0.5"
            />
            <Input
              {...control}
              dir="ltr"
              autoComplete="off"
              spellCheck={false}
              maxLength={7}
              value={values.accent_color}
              onChange={(e) => set('accent_color', e.target.value)}
              placeholder={TELEGRAM_BLUE}
              className="w-28"
            />
            {values.accent_color !== '' && (
              <Button variant="ghost" size="sm" icon={X} onClick={() => set('accent_color', '')}>
                رنگ تلگرام
              </Button>
            )}
          </div>
        )}
      </Field>
      <SwitchRow
        label="تمام‌صفحه روی گوشی"
        hint="روشن باشد، مینی‌اپ روی گوشی همه صفحه را می‌گیرد؛ خاموش باشد، مثل یک برگه از پایین صفحه باز می‌شود."
        checked={values.fullscreen}
        onCheckedChange={(checked) => set('fullscreen', checked)}
        error={error('fullscreen')}
      />
    </SectionCard>
  )
}

/** The announcement card's draft: its words and its link, blank for none. */
type AnnouncementDraft = Required<Pick<MiniAppSettingsRequest, 'announcement' | 'announcement_url'>>

function announcementDraftOf(miniApp: MiniAppSettings): AnnouncementDraft {
  return { announcement: miniApp.announcement ?? '', announcement_url: miniApp.announcement_url ?? '' }
}

/** A word to every customer on the app's home screen, and where it leads when pressed (https only). */
function AnnouncementCard({ miniApp }: { miniApp: MiniAppSettings }) {
  const group = useMiniAppGroup(miniApp, announcementDraftOf, (values) => values)
  const { values, set, error } = group

  return (
    <SectionCard form={group} title="اطلاعیه" description="پیامی برای همه مشتری‌ها در صفحه اول مینی‌اپ؛ مثلا خبر یک تخفیف یا قطعی برنامه‌ریزی‌شده. خالی بماند، اطلاعیه‌ای نشان داده نمی‌شود.">
      <Field id="mini_app_announcement" label="متن اطلاعیه" optional error={error('announcement')}>
        <NoteInput rows={3} max={ANNOUNCEMENT_MAX} value={values.announcement} onChange={(e) => set('announcement', e.target.value)} />
      </Field>
      <Field
        id="mini_app_announcement_url"
        label="لینک اطلاعیه"
        optional
        error={error('announcement_url')}
        hint={
          <>
            با زدن اطلاعیه باز می‌شود؛ فقط آدرس https، مثل <bdi dir="ltr">https://t.me/channel</bdi>.
          </>
        }
      >
        <Input
          dir="ltr"
          inputMode="url"
          autoComplete="off"
          spellCheck={false}
          value={values.announcement_url}
          onChange={(e) => set('announcement_url', e.target.value)}
          placeholder="https://t.me/channel"
        />
      </Field>
    </SectionCard>
  )
}

/** The reviews card's draft: its switch, as the request takes it. */
type ReviewsDraft = Required<Pick<MiniAppSettingsRequest, 'reviews_enabled'>>

function reviewsDraftOf(miniApp: MiniAppSettings): ReviewsDraft {
  return { reviews_enabled: miniApp.reviews_enabled }
}

/** Whether the app shows the approved reviews and takes new ones — from its customers, who are always signed in there. */
function ReviewsCard({ miniApp }: { miniApp: MiniAppSettings }) {
  const group = useMiniAppGroup(miniApp, reviewsDraftOf, (values) => values)
  const { values, set, error } = group

  return (
    <SectionCard form={group} title="نظرات مشتریان در مینی‌اپ" description="مشتری‌ها در مینی‌اپ درباره فروشگاه نظر می‌نویسند و مینی‌اپ نظرهایی را که پشتیبانی تایید کرده نشان می‌دهد.">
      <SwitchRow
        label="نظرات در مینی‌اپ"
        hint="روشن باشد، مینی‌اپ نظرهای تاییدشده را نشان می‌دهد و نظر تازه می‌گیرد؛ خاموش باشد، هیچ‌کدام."
        checked={values.reviews_enabled}
        onCheckedChange={(checked) => set('reviews_enabled', checked)}
        error={error('reviews_enabled')}
      />
      <Callout tone="info" icon={Info}>
        هر نظر تا وقتی در صفحه{' '}
        <TextLink inline to="/reviews">
          نظرات
        </TextLink>{' '}
        تایید نشود، در مینی‌اپ دیده نمی‌شود. مشتری در مینی‌اپ همیشه با حساب تلگرامش وارد است، پس هر نظر به نام یک مشتری است.
      </Callout>
    </SectionCard>
  )
}
