import { useState } from 'react'
import { Info } from 'lucide-react'
import { useBotSettingsGroup } from '@/components/bot/settings/use-bot-settings-group'
import { Callout } from '@/components/callout'
import { Field } from '@/components/field'
import { SectionCard } from '@/components/section-card'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { api } from '@/lib/api'
import type { BotClientNamesSettingsRequest, BotSettingsData, BotSettingsResponse } from '@/lib/api-types'

/** What a pattern left blank is kept as: the names every shop's clients had before («amir_3», «test_amir_3»). */
const PATTERN_DEFAULT = '{username}_{seq}'
const TEST_PATTERN_DEFAULT = 'test_{username}_{seq}'

type PatternField = 'name_pattern' | 'test_name_pattern'
type Variables = BotSettingsResponse['name_variables']

/**
 * The name `pattern` — or `fallback` while it is blank — gives the server's sample customer, each variable by its
 * `example`; a variable the server does not list stays as typed.
 */
export function exampleName(pattern: string, fallback: string, variables: Variables): string {
  const values = new Map(variables.map((variable) => [variable.name, variable.example]))
  return (pattern.trim() || fallback).replace(/\{([a-z_]+)\}/g, (token, name: string) => values.get(name) ?? token)
}

/**
 * «نام کاربری سرویس»: what a service's client is called on its panel — the pattern a sold service's name follows and a
 * free test's, of the variables the server lists (`name_variables`; a tap writes one at the end of the pattern last
 * edited), each with the name it would give the server's sample customer — and whether a buyer may instead take a
 * random name or type their own at the checkout.
 */
export function ClientNamesSection({ settings, variables }: { settings: BotSettingsData; variables: Variables }) {
  const group = useBotSettingsGroup(
    {
      name_pattern: settings.name_pattern,
      test_name_pattern: settings.test_name_pattern,
      name_random: settings.name_random,
      name_custom: settings.name_custom,
    } satisfies BotClientNamesSettingsRequest,
    (values) => api.put('/bot/settings/client_names', values),
  )
  const { values, set, error } = group
  const [editing, setEditing] = useState<PatternField>('name_pattern')

  const pattern = (field: PatternField, label: string, fallback: string) => (
    <Field
      id={`bot_${field}`}
      label={label}
      error={error(field)}
      hint={
        <>
          نمونه:{' '}
          <bdi dir="ltr" className="text-foreground">
            {exampleName(values[field], fallback, variables)}
          </bdi>
        </>
      }
    >
      <Input
        dir="ltr"
        autoComplete="off"
        spellCheck={false}
        value={values[field]}
        onChange={(e) => set(field, e.target.value)}
        onFocus={() => setEditing(field)}
        placeholder={fallback}
        className="sm:max-w-sm"
      />
    </Field>
  )

  return (
    <SectionCard
      form={group}
      title="نام کاربری سرویس"
      description="نام کلاینت هر سرویس روی پنل سرور، که در لیست کلاینت‌های پنل هم همین را می‌بینید. نام کاربری سرویس‌های تازه با الگو ساخته می‌شود؛ سرویس‌هایی که از قبل ساخته شده‌اند نام کاربری‌شان عوض نمی‌شود."
    >
      {pattern('name_pattern', 'الگوی نام کاربری سرویس', PATTERN_DEFAULT)}
      {pattern('test_name_pattern', 'الگوی نام کاربری اکانت تست', TEST_PATTERN_DEFAULT)}
      <div className="grid gap-2">
        <div className="grid gap-0.5">
          <p className="text-body font-medium">متغیرها</p>
          <p className="text-footnote leading-relaxed text-muted-foreground">
            روی هر متغیر بزنید تا به انتهای الگویی که ویرایش می‌کنید اضافه شود. بیرون از متغیرها فقط حروف و رقم انگلیسی و _ - . @ پذیرفته می‌شود، و الگو باید {'{n}'} یا {'{random}'} را داشته باشد، یا{' '}
            {'{seq}'} را کنار {'{username}'}، {'{tg_id}'} یا {'{user_id}'}، تا نام کاربری سرویس‌ها تکراری نشود.
          </p>
        </div>
        <ul className="grid gap-1.5 sm:grid-cols-2">
          {variables.map((variable) => (
            <li key={variable.name}>
              <button
                type="button"
                onClick={() => set(editing, `${values[editing]}{${variable.name}}`)}
                className="grid h-full w-full gap-0.5 rounded-lg border border-border px-2.5 py-1.5 text-start transition-colors duration-150 outline-none hover:bg-fill focus-visible:focus-ring"
              >
                <bdi dir="ltr" className="text-footnote text-info">{`{${variable.name}}`}</bdi>
                <span className="text-footnote leading-relaxed text-muted-foreground">{variable.description}</span>
              </button>
            </li>
          ))}
        </ul>
      </div>
      <SwitchRow
        label="نام رندوم"
        hint="در صفحه پرداخت خرید، مشتری با دکمه «🎲 رندوم» یک نام کاربری تصادفی شش‌حرفی برای سرویسش می‌گیرد."
        checked={values.name_random}
        onCheckedChange={(checked) => set('name_random', checked)}
        error={error('name_random')}
      />
      <SwitchRow
        label="نام دلخواه"
        hint="در صفحه پرداخت خرید، مشتری با دکمه «✏️ دلخواه» نام کاربری سرویسش را می‌نویسد: بین ۳ تا ۳۲ کاراکتر از حروف و رقم انگلیسی، _ و -، و نامی که روی همان سرور گرفته نشده باشد."
        checked={values.name_custom}
        onCheckedChange={(checked) => set('name_custom', checked)}
        error={error('name_custom')}
      />
      <Callout tone="info" icon={Info}>
        تا یکی از این دو روشن نباشد، از مشتری چیزی پرسیده نمی‌شود و همه سرویس‌ها با الگو نام می‌گیرند. اگر نامی که مشتری انتخاب کرده تا تحویل سرویس روی پنل گرفته شود، سرویس با الگو نام می‌گیرد.
      </Callout>
    </SectionCard>
  )
}
