/**
 * How long the bytes stay with their link after the press: a large file may still be read from them by the browser's
 * download (Safari and Firefox read the link's bytes after the click, not during it).
 */
const KEEP_MS = 60_000

/**
 * Hands bytes the panel fetched with its session (a backup) to the browser to save as `name`: a link to them, pressed
 * once, then let go a minute later.
 */
export function saveFile(bytes: Blob, name: string): void {
  const address = URL.createObjectURL(bytes)
  const link = document.createElement('a')
  link.href = address
  link.download = name
  link.hidden = true
  document.body.append(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(address), KEEP_MS)
}
