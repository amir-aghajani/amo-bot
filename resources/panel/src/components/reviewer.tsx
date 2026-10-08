/** A Persian letter: a word, not an identifier. */
const PERSIAN = /[؀-ۿ]/

/**
 * Who decided something — a row's `reviewer`: a login, the agent's @bot, a bot admin's @handle, tg:<id> or user#<id> (in
 * the report group or on the shop's website), each an identifier set apart left to right in the mono; or «پشتیبانی», how
 * anyone but the owner reads the owner (Auth\Services\Reviewers), Persian like the line around it.
 */
export function Reviewer({ name }: { name: string }) {
  return PERSIAN.test(name) ? <bdi>{name}</bdi> : <bdi dir="ltr">{name}</bdi>
}
