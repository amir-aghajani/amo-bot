import { Segmented, TextInput } from '@/apps/app/ui/fields'
import { Section } from '@/apps/app/ui/section'
import type { ClientNameMode } from '@/lib/api-types'
import { toLatinDigits } from '@/lib/format'
import { cn } from '@/lib/utils'

/*
 * How a new service is named on its panel, while the shop lets the buyer choose (`shop.name_modes` of `GET /` past
 * `sequential`): by the shop's pattern, a random name, or one they type — as the bot's «ترتیبی», «رندوم» and «دلخواه».
 */

export interface ServiceName {
  mode: ClientNameMode
  /** What the customer typed, for `custom`. */
  typed: string
}

/** The shop's pattern: what a checkout starts with, and all it sends while the shop offers nothing else. */
export const BY_PATTERN: ServiceName = { mode: 'sequential', typed: '' }

const MODES: Record<ClientNameMode, { label: string; hint: string }> = {
  sequential: { label: 'ترتیبی', hint: 'سرویس با الگوی نام‌گذاری فروشگاه نام می‌گیرد.' },
  random: { label: 'رندوم', hint: 'یک نام تصادفی شش‌حرفی به سرویس داده می‌شود.' },
  custom: { label: 'دلخواه', hint: 'بین ۳ تا ۳۲ کاراکتر، فقط حروف و رقم انگلیسی، _ و -.' },
}

/** A name the shop's rule takes (Persian digits read as Latin ones). */
const NAME = /^[A-Za-z0-9_-]{3,32}$/

const typedName = (name: ServiceName): string => toLatinDigits(name.typed.trim())

/** What the order sends of it: nothing for the shop's pattern. */
export function nameRequest(name: ServiceName): { name_mode?: ClientNameMode; client_name?: string } {
  if (name.mode === 'sequential') return {}
  if (name.mode === 'random') return { name_mode: 'random' }

  return { name_mode: 'custom', client_name: typedName(name) }
}

/** Whether the order can be sent with it: a name typed the shop's rule takes, or no name to type. */
export function nameReady(name: ServiceName): boolean {
  return name.mode !== 'custom' || NAME.test(typedName(name))
}

interface ServiceNameFieldProps {
  /** The ways the shop offers (`shop.name_modes`); with one, nothing is asked. */
  modes: readonly ClientNameMode[]
  value: ServiceName
  onChange: (name: ServiceName) => void
  /** The order's refusal of the name (`client_name` or `name_mode`). */
  error?: string
}

export function ServiceNameField({ modes, value, onChange, error }: ServiceNameFieldProps) {
  if (modes.length < 2) return null

  // The words under the control start where the card's words do (12 + 4px), the box inside it a filled field, flat.
  return (
    <Section title="نام کاربری سرویس">
      <div className="flex flex-col gap-3 p-3">
        <Segmented value={value.mode} options={modes.map((mode) => ({ value: mode, label: MODES[mode].label }))} onChange={(mode) => onChange({ ...value, mode })} />
        {value.mode === 'custom' && (
          <TextInput
            ltr
            aria-label="نام کاربری دلخواه"
            className="bg-fill shadow-none"
            value={value.typed}
            onChange={(event) => onChange({ ...value, typed: event.target.value })}
            placeholder="my-vpn"
            maxLength={32}
            autoCapitalize="off"
            autoComplete="off"
            autoCorrect="off"
            spellCheck={false}
            invalid={error !== undefined}
          />
        )}
        <p className={cn('px-1 text-caption', error === undefined ? 'text-hint' : 'text-danger')} role={error === undefined ? undefined : 'alert'}>
          {error ?? MODES[value.mode].hint}
        </p>
      </div>
    </Section>
  )
}
