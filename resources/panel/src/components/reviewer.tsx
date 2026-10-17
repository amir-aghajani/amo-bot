/** A Persian letter: a word, not an identifier. */
const PERSIAN = /[؀-ۿ]/

/**
 * The identifiers the server keeps of an admin without a handle (Auth\Services\Reviewers): a customer's or an agent's
 * number, a Telegram id — each said in words with the number set apart, never as the raw `user#12`.
 */
const KEPT: { pattern: RegExp; word: string; number: (digits: string) => string }[] = [
  { pattern: /^user#(\d+)$/, word: 'کاربر', number: (digits) => `#${digits}` },
  { pattern: /^agent#(\d+)$/, word: 'نماینده', number: (digits) => `#${digits}` },
  { pattern: /^tg:(\d+)$/, word: 'شناسه تلگرام', number: (digits) => digits },
]

/**
 * Who decided something (a row's `reviewer`): the admin's own name where the server read it (`person`: the row's
 * `reviewer_name`, a manual order's `by_name`), in its own direction; else an identifier — a login, an agent's @bot, an
 * admin's @handle — set apart left to right; an admin kept by a number (`user#12`, `tg:<id>`) in words, the number apart
 * («کاربر #12»); or the Persian «پشتیبانی», which is how anyone but the owner sees the owner.
 */
export function Reviewer({ name, person }: { name: string; person?: string | null }) {
  if (person) return <bdi>{person}</bdi>
  if (PERSIAN.test(name)) return <bdi>{name}</bdi>

  for (const { pattern, word, number } of KEPT) {
    const digits = pattern.exec(name)?.[1]
    if (digits !== undefined) {
      return (
        <span>
          {word} <bdi dir="ltr">{number(digits)}</bdi>
        </span>
      )
    }
  }

  return <bdi dir="ltr">{name}</bdi>
}
