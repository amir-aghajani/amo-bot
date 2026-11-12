import { describe, expect, it, vi } from 'vitest'
import { saveFile } from '@/lib/save-file'

/*
 * A file the panel fetched, handed to the browser to save (lib/save-file): a link to its bytes pressed once, by the name
 * given — the bytes kept a minute after, while the browser's download may still be reading them.
 */

describe('saving a file', () => {
  it('presses a link to its bytes by its name, and lets the bytes go a minute later — not at once', () => {
    vi.useFakeTimers()
    const bytes = new Blob(['gzip bytes'], { type: 'application/gzip' })
    const create = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:backup')
    const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
    const pressed: { href: string; download: string }[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      pressed.push({ href: this.getAttribute('href') ?? '', download: this.download })
    })

    saveFile(bytes, 'amobot-installation-20261112.json.gz')

    expect(create).toHaveBeenCalledWith(bytes)
    expect(pressed).toEqual([{ href: 'blob:backup', download: 'amobot-installation-20261112.json.gz' }])
    expect(document.querySelector('a[download]')).toBeNull()

    vi.advanceTimersByTime(59_999)
    expect(revoke).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)
    expect(revoke).toHaveBeenCalledWith('blob:backup')
  })
})
