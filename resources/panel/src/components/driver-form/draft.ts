import type { DriverDescription, DriverValues, FieldDescription, SecretState } from '@/lib/api-types'
import { digitsOnly } from '@/lib/format'
import { originOf } from '@/lib/utils'

/*
 * A driver's form as a screen holds it (components/driver-form): drawn from the driver's description alone, read and
 * sent as the server's Form reads it — a field shown only while the fields its `when` names hold a value it lists, a
 * secret sent only when typed, kept otherwise while the fields it belongs with stay.
 */

/** A driver's form as typed: each field's text under its name — a switch true or false —, and a secret's `clear_<name>`. */
export type DriverDraft = Record<string, string | boolean>

/** What a driver's form sends: its fields shown, by name, as typed — the API holds it to the driver's own request. */
export type DriverPayload = Record<string, string | boolean>

/** What a screen shows of a field kept: a secret's state, or the value config.php holds. */
type Kept = DriverValues[string]

/** Nothing kept of a secret (the installer's, a driver other than the one saved). */
export const NOTHING_KEPT: SecretState = { set: false, hint: '' }

/** A secret's state among what is kept. */
export function isSecretState(value: Kept | undefined): value is SecretState {
  return typeof value === 'object' && !Array.isArray(value)
}

/**
 * The draft a driver's form starts from: what the server keeps for it (`stored`), else each field's default — a list
 * typed out ("50, 100"), a switch on or off — and every secret blank, not cleared.
 */
export function driverDraft(driver: DriverDescription, stored?: DriverValues): DriverDraft {
  const draft: DriverDraft = {}
  for (const field of driver.fields) {
    if (field.secret) {
      draft[field.name] = ''
      draft[`clear_${field.name}`] = false
      continue
    }
    const kept = stored?.[field.name]
    const value = kept === undefined || isSecretState(kept) ? field.default : kept
    draft[field.name] = field.type === 'toggle' ? value === true : Array.isArray(value) ? value.join(', ') : value === null ? '' : String(value)
  }
  return draft
}

/**
 * The fields shown, in order: each whose `when` holds — every field it names shown before it and holding one of the
 * values listed, a switch spelled "true" or "false" — as the server reads the form (a field not shown is neither read nor
 * refused, and stays as it is kept).
 */
export function shownFields(driver: DriverDescription, draft: DriverDraft): FieldDescription[] {
  const shown = new Set<string>()
  return driver.fields.filter((field) => {
    const holds = Object.entries(field.when).every(([name, values]) => shown.has(name) && values.includes(String(draft[name] ?? '')))
    if (holds) shown.add(field.name)
    return holds
  })
}

/** What a driver's form sends: its fields shown, as typed — a secret only when typed, beside its clear flag. */
export function driverPayload(driver: DriverDescription, draft: DriverDraft): DriverPayload {
  const payload: DriverPayload = {}
  for (const field of shownFields(driver, draft)) {
    const value = draft[field.name] ?? (field.type === 'toggle' ? false : '')
    if (field.secret) {
      if (value !== '') payload[field.name] = value
      payload[`clear_${field.name}`] = draft[`clear_${field.name}`] === true
    } else {
      payload[field.name] = value
    }
  }
  return payload
}

/**
 * Whether a secret left blank would keep the stored one while a field it belongs with (`bound_to`), shown, moved from
 * what is kept — what the server refuses, in the field's `moved` words: each read as the server identifies it, a number
 * by its digits, a web address by its origin.
 */
export function leftBehind(driver: DriverDescription, field: FieldDescription, draft: DriverDraft, stored?: DriverValues): boolean {
  const kept = stored?.[field.name]
  if (!isSecretState(kept) || !kept.set || draft[field.name] !== '' || draft[`clear_${field.name}`] === true) return false

  const shown = shownFields(driver, draft)
  return field.bound_to.some((name) => {
    const bound = shown.find((candidate) => candidate.name === name)
    const was = stored?.[name]
    return bound !== undefined && was !== undefined && !isSecretState(was) && identity(bound, draft[name] ?? '') !== identity(bound, was)
  })
}

/** What identifies a field's value, as the server compares a bound secret's fields: a number its digits, an address its origin. */
function identity(field: FieldDescription, value: string | boolean | number | number[]): string {
  const text = Array.isArray(value) ? value.join(',') : String(value).trim()
  if (field.type === 'number') return digitsOnly(text)
  if (field.type === 'url') return originOf(text)
  return text
}
