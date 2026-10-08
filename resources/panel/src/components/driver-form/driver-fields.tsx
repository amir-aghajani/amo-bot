import { Disclosure } from '@/components/disclosure'
import { isSecretState, leftBehind, NOTHING_KEPT, shownFields, type DriverDraft } from '@/components/driver-form/draft'
import { Field } from '@/components/field'
import { PageTabs } from '@/components/page-tabs'
import { SecretField } from '@/components/secret-field'
import { SwitchRow } from '@/components/switch-row'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import type { DriverDescription, DriverValues, FieldDescription, FieldType } from '@/lib/api-types'
import { digitsOnly, formatCardNumber } from '@/lib/format'

/** The kinds whose content is Latin whatever the field says: drawn left to right. */
const LTR: ReadonlySet<FieldType> = new Set(['number', 'amount', 'list', 'email', 'url', 'path'])

/** The keyboard a phone offers for a kind. */
const INPUT_MODES: Partial<Record<FieldType, 'numeric' | 'decimal' | 'email' | 'url'>> = { number: 'numeric', amount: 'decimal', email: 'email', url: 'url' }

/** The most choices drawn side by side (a mode, an encryption); more go in a list. */
const SIDE_BY_SIDE = 3

/** A bank card's digits: what a card field holds at most. */
const CARD_DIGITS = 16

interface DriverFieldsProps {
  driver: DriverDescription
  /**
   * What the server keeps for the driver — a secret's state, the values a bound secret was saved with —; undefined while
   * nothing is (the installer, a driver other than the one saved).
   */
  stored?: DriverValues
  draft: DriverDraft
  set: (name: string, value: string | boolean) => void
  error: (name: string) => string | undefined
  /** What the fields' ids begin with: unique on the page (`db`, `mail`). */
  idPrefix: string
}

/**
 * A driver's form drawn from its description alone: every field shown by its kind — a line of text, a number (its unit
 * beside its label), an amount, a list typed as text, a switch, a choice (a few side by side, more in a list), a secret
 * kept unless typed again or cleared, an email, a web address, a path, a few lines, a bank card's number in groups of
 * four —, Latin content left to right, a field shown only while the ones it names hold a value it lists, and the rarely
 * needed ones under «تنظیمات پیشرفته». A secret kept for an address that moved says so before it is sent, in the
 * server's words.
 */
export function DriverFields({ driver, stored, draft, set, error, idPrefix }: DriverFieldsProps) {
  const shown = shownFields(driver, draft)
  const control = (field: FieldDescription) => (
    <DriverField
      key={field.name}
      id={`${idPrefix}_${field.name}`}
      field={field}
      draft={draft}
      set={set}
      error={error(field.name)}
      stored={stored}
      movedHint={leftBehind(driver, field, draft, stored)}
    />
  )
  const basic = shown.filter((field) => !field.advanced)
  const advanced = shown.filter((field) => field.advanced)

  return (
    <>
      {basic.length > 0 && <div className="grid gap-4 sm:grid-cols-2">{basic.map(control)}</div>}
      {advanced.length > 0 && (
        <Disclosure label="تنظیمات پیشرفته">
          <div className="grid gap-4 sm:grid-cols-2">{advanced.map(control)}</div>
        </Disclosure>
      )}
    </>
  )
}

interface DriverFieldProps {
  id: string
  field: FieldDescription
  draft: DriverDraft
  set: (name: string, value: string | boolean) => void
  error: string | undefined
  stored: DriverValues | undefined
  /** A secret kept for an address that moved: its `moved` words in place of its hint. */
  movedHint: boolean
}

function DriverField({ id, field, draft, set, error, stored, movedHint }: DriverFieldProps) {
  const value = draft[field.name]
  const text = typeof value === 'string' ? value : ''
  const label = field.unit === null ? field.label : `${field.label} (${field.unit})`
  const hint = field.hint ?? undefined

  switch (field.type) {
    case 'toggle':
      return <SwitchRow label={label} hint={hint} checked={value === true} onCheckedChange={(checked) => set(field.name, checked)} error={error} bordered={false} className="sm:col-span-2" />
    case 'secret': {
      const kept = stored?.[field.name]
      return (
        <SecretField
          id={id}
          label={label}
          stored={isSecretState(kept) ? kept : NOTHING_KEPT}
          value={text}
          onChange={(next) => set(field.name, next)}
          clear={draft[`clear_${field.name}`] === true}
          onClear={(clear) => set(`clear_${field.name}`, clear)}
          error={error}
          hint={movedHint ? (field.moved ?? hint) : hint}
          optional={!field.required}
        />
      )
    }
    case 'choice':
      return field.options.length <= SIDE_BY_SIDE ? (
        <ChoiceRow id={id} field={field} label={label} value={text} set={set} error={error} hint={hint} />
      ) : (
        <Field id={id} label={label} hint={hint} error={error} optional={!field.required}>
          {(control) => (
            <Select value={text} onValueChange={(next) => set(field.name, next)}>
              <SelectTrigger {...control} className="w-full sm:max-w-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {field.options.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    <bdi>{option.label}</bdi>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
      )
    case 'textarea':
      return (
        <Field id={id} label={label} hint={hint} error={error} optional={!field.required} className="sm:col-span-2">
          <Textarea dir={field.ltr ? 'ltr' : undefined} value={text} onChange={(e) => set(field.name, e.target.value)} placeholder={field.placeholder ?? undefined} />
        </Field>
      )
    case 'card':
      // As the card is printed — its digits in groups of four, typed on a phone's number pad —, kept as its digits alone.
      return (
        <Field id={id} label={label} hint={hint} error={error} optional={!field.required}>
          <Input
            dir="ltr"
            inputMode="numeric"
            autoComplete="off"
            spellCheck={false}
            value={formatCardNumber(text)}
            onChange={(e) => set(field.name, digitsOnly(e.target.value).slice(0, CARD_DIGITS))}
            placeholder={field.placeholder ?? undefined}
          />
        </Field>
      )
    default:
      return (
        <Field id={id} label={label} hint={hint} error={error} optional={!field.required}>
          <Input
            dir={field.ltr || LTR.has(field.type) ? 'ltr' : undefined}
            inputMode={INPUT_MODES[field.type]}
            autoComplete="off"
            spellCheck={false}
            value={text}
            onChange={(e) => set(field.name, e.target.value)}
            placeholder={field.placeholder ?? undefined}
          />
        </Field>
      )
  }
}

interface ChoiceRowProps {
  id: string
  field: FieldDescription
  label: string
  value: string
  set: (name: string, value: string) => void
  error: string | undefined
  hint: string | undefined
}

/** A few choices side by side (PageTabs as a radio group): one field of the form, focused whole when it is refused. */
function ChoiceRow({ id, field, label, value, set, error, hint }: ChoiceRowProps) {
  return (
    <div
      role="group"
      aria-labelledby={`${id}-label`}
      aria-describedby={error || hint ? `${id}-line` : undefined}
      data-invalid={error ? '' : undefined}
      tabIndex={error ? -1 : undefined}
      className="grid gap-1.5 rounded-md outline-none focus-visible:focus-ring sm:col-span-2"
    >
      <span id={`${id}-label`} className="text-body font-medium">
        {label}
      </span>
      <PageTabs as="choice" size="sm" className="w-fit" aria-label={field.label} value={value} tabs={field.options} onChange={(next) => set(field.name, next)} />
      {(error || hint) && (
        <p id={`${id}-line`} className={error ? 'text-footnote text-danger' : 'text-footnote text-muted-foreground'}>
          {error ?? hint}
        </p>
      )}
    </div>
  )
}
