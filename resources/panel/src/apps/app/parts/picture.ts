import { useCallback, useEffect, useRef, useState, type ChangeEvent } from 'react'

/*
 * A picture the customer sends the shop from the phone — a card transfer's receipt, a ticket's message: a JPEG, PNG or
 * WebP of 10 MB at most, as the shop takes both. A large photo is drawn again smaller first — a host's PHP may take a
 * couple of megabytes at most —, which also leaves behind what the camera wrote into it (its place). One past the limits
 * is refused as it is picked, in the shop's words.
 */

/** What the picker offers — these three, not image/*: an iPhone then hands over its HEIC photo as a JPEG. */
export const PICTURE_TYPES = 'image/jpeg,image/png,image/webp'

/** The largest picture the shop takes (Users\Services\CustomerPictures::MAX_BYTES; a receipt's likewise). */
const MAX_BYTES = 10 * 1024 * 1024
/** A photo past this size is sent smaller. */
const LARGE_BYTES = 1_500_000
/** The longest side a large photo is sent at (the shop keeps 2560 at most). */
const LONGEST_SIDE = 2048

const TOO_LARGE = 'حجم تصویر حداکثر ۱۰ مگابایت می‌تواند باشد.'
const WRONG_KIND = 'تصویر باید JPG، PNG یا WebP باشد.'

/** The picture as it is sent: a large photo drawn again as a smaller JPEG; any other, or one the browser cannot draw, as it is. */
async function shrunk(file: File): Promise<Blob> {
  if (file.size <= LARGE_BYTES) return file
  try {
    // createImageBitmap turns the picture the way the camera held it.
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, LONGEST_SIDE / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    bitmap.close()
    const drawn = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85))

    return drawn !== null && drawn.size < file.size ? new File([drawn], 'picture.jpg', { type: 'image/jpeg' }) : file
  } catch {
    return file
  }
}

/** The picture picked, and the address an <img> shows it at (let go once another is picked, or it is dropped). */
export interface Picked {
  file: Blob
  preview: string
}

/** The file an <input type="file"> was given — the input emptied, so the same picture can be picked again after a refusal. */
export function fileOf(event: ChangeEvent<HTMLInputElement>): File | undefined {
  const file = event.target.files?.[0]
  event.target.value = ''

  return file
}

/** The picture being attached: `pick` checks and readies one (or says why not), `clear` drops it. */
export function usePicture() {
  const [picked, setPicked] = useState<Picked | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  /** Whether the screen is still there to show a picture readied (a large one takes a moment to draw again). */
  const live = useRef(true)
  useEffect(() => {
    live.current = true
    return () => {
      live.current = false
    }
  }, [])
  useEffect(() => {
    if (picked === null) return
    const { preview } = picked

    return () => URL.revokeObjectURL(preview)
  }, [picked])

  const pick = useCallback(async (file: File) => {
    if (file.type !== '' && !PICTURE_TYPES.split(',').includes(file.type)) {
      setPicked(null)
      setProblem(WRONG_KIND)
      return
    }
    const ready = await shrunk(file)
    // Left while it was drawn again: no address for a picture nothing shows (it would never be let go).
    if (!live.current) return
    if (ready.size > MAX_BYTES) {
      setPicked(null)
      setProblem(TOO_LARGE)
      return
    }
    setProblem(null)
    setPicked({ file: ready, preview: URL.createObjectURL(ready) })
  }, [])

  const clear = useCallback(() => {
    setPicked(null)
    setProblem(null)
  }, [])

  return { picked, problem, pick, clear }
}
