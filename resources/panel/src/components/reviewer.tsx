/** A Persian letter: a word, not an identifier. */
const PERSIAN = /[؀-ۿ]/

/**
 * Who decided something (a row's `reviewer`): an identifier — a login, an agent's @bot, an admin's @handle, tg:<id>,
 * user#<id> — set apart left to right; or the Persian «پشتیبانی», which is how anyone but the owner sees the owner.
 */
export function Reviewer({ name }: { name: string }) {
  return PERSIAN.test(name) ? <bdi>{name}</bdi> : <bdi dir="ltr">{name}</bdi>
}
