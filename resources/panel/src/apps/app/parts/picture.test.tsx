import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { usePicture } from '@/apps/app/parts/picture'

/*
 * The picture being attached (parts/picture): a large photo is drawn again smaller first, which takes a moment — the
 * screen left meanwhile, no address on the device is made for it (nothing would ever let it go).
 */

describe('a picture being attached', () => {
  it('makes no address for a large photo still being drawn again when the screen is left', async () => {
    let unread: (error: Error) => void = () => undefined
    vi.stubGlobal('createImageBitmap', () => new Promise<ImageBitmap>((_resolve, reject) => (unread = reject)))
    const address = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:picture')
    const { result, unmount } = renderHook(() => usePicture())

    let picking: Promise<void> | undefined
    act(() => {
      picking = result.current.pick(new File([new Uint8Array(1_600_000)], 'photo.jpg', { type: 'image/jpeg' }))
    })
    unmount()
    // A photo the browser cannot draw again: it goes as it is.
    unread(new Error('The source image could not be decoded.'))
    await picking

    expect(address).not.toHaveBeenCalled()
  })

  it('makes one for a photo readied while the screen is there', async () => {
    const address = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:picture')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const { result } = renderHook(() => usePicture())

    await act(() => result.current.pick(new File(['x'], 'receipt.png', { type: 'image/png' })))

    expect(address).toHaveBeenCalledTimes(1)
    expect(result.current.picked?.preview).toBe('blob:picture')
  })
})
