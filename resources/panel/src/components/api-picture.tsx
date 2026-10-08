import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download, ImageOff, Maximize2 } from 'lucide-react'
import { ErrorState } from '@/components/error-state'
import { Modal } from '@/components/modal'
import { Button } from '@/components/ui/button'
import { api, mediaUrl } from '@/lib/api'
import { queryKeys } from '@/lib/query-keys'
import { cn } from '@/lib/utils'

/** Where a picture sits: the width of a dialog's column (a payment's receipt), or a thumbnail (a ticket message's). */
type Place = 'column' | 'thumbnail'

const FRAME: Record<Place, string> = {
  column: 'w-full rounded-xl',
  thumbnail: 'w-72 max-w-full rounded-lg',
}

interface ApiPictureProps {
  /** Its address under the panel's API: «/payments/7/receipt». */
  path: string
  /** What it shows — for assistive tech, and the full size's title: «رسید پرداخت #7». */
  alt: string
  /** Its file's name, as a download saves it. */
  name: string
  /** What it is, as its words say: «رسید» (… بارگذاری نشد), «تصویر». */
  what: string
  /** The browser cannot draw it, as its name says (a HEIC photo sent as a file): what there is, asked at once. */
  undrawable?: boolean
  place: Place
}

/**
 * A picture the panel's API serves — a payment's receipt, a ticket message's —, drawn by the browser from its address
 * (lib/api's mediaUrl: the shop the tab shows goes in it), opening at full size in a dialog with its download. When the
 * browser cannot draw it — a name it does not draw, a picture that would not load —, the panel asks the address what
 * there is (lib/api's probe): the file, there to download; or the server's word on why there is none to show — gone
 * (said so, nothing more), or out of reach for now (with a way to try again, which draws it afresh: a refusal is never
 * cached, so the browser asks again).
 */
export function ApiPicture({ path, alt, name, what, undrawable = false, place }: ApiPictureProps) {
  const [failed, setFailed] = useState(undrawable)
  const [attempt, setAttempt] = useState(0)
  const [open, setOpen] = useState(false)
  const url = mediaUrl(path)
  // Whether the file is there: a read's answer must be something (true), its failure says why it is not.
  const check = useQuery({ queryKey: queryKeys.pictureCheck(path), queryFn: () => api.probe(path).then(() => true), enabled: failed, retry: false, gcTime: 0 })

  if (failed) {
    if (check.isPending || check.isFetching) return <PictureNote place={place}>در حال بررسی {what}…</PictureNote>
    if (check.error) {
      return (
        <ErrorState
          what={what}
          error={check.error}
          onRetry={() => {
            setFailed(false)
            setAttempt((current) => current + 1)
          }}
        />
      )
    }
    return (
      <PictureNote place={place}>
        <span>مرورگر این {what} را نشان نمی‌دهد (مثلا قالب HEIC)؛ دانلودش کنید.</span>
        <DownloadPicture url={url} name={name} what={what} />
      </PictureNote>
    )
  }

  return (
    <>
      {/* What the picture opens says so on it, for every pointer and for assistive tech alike. */}
      <button type="button" onClick={() => setOpen(true)} className={cn('group grid overflow-hidden border border-border bg-fill outline-none focus-visible:focus-ring', FRAME[place])}>
        <img
          key={attempt}
          src={url}
          alt={alt}
          loading="lazy"
          className={cn('mx-auto w-auto max-w-full object-contain', place === 'column' ? 'max-h-[28rem]' : 'max-h-56')}
          onError={() => setFailed(true)}
        />
        <span className="flex items-center justify-center gap-1.5 border-t border-border px-3 py-1.5 text-footnote text-muted-foreground transition-colors group-hover:text-foreground">
          <Maximize2 className="size-3.5" aria-hidden />
          نمایش در اندازه کامل
        </span>
      </button>

      <Modal open={open} onClose={() => setOpen(false)} size="xl" title={alt} description={<bdi dir="ltr">{name}</bdi>}>
        <div className="grid gap-4">
          <img
            src={url}
            alt={alt}
            className="mx-auto max-h-[70vh] w-auto max-w-full rounded-lg object-contain"
            onError={() => {
              setOpen(false)
              setFailed(true)
            }}
          />
          <div className="flex justify-end">
            <DownloadPicture url={url} name={name} what={what} />
          </div>
        </div>
      </Modal>
    </>
  )
}

/** The picture saved under its own name. */
function DownloadPicture({ url, name, what }: { url: string; name: string; what: string }) {
  return (
    <Button asChild variant="secondary" size="sm">
      <a href={url} download={name}>
        <Download aria-hidden />
        دانلود {what}
      </a>
    </Button>
  )
}

/** In a picture's place, a word on why it is not drawn — and what may be done. */
export function PictureNote({ place, children }: { place: Place; children: ReactNode }) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center gap-3 border border-dashed border-border px-4 py-4 text-center text-body text-muted-foreground',
        FRAME[place],
        place === 'column' ? 'min-h-48' : 'min-h-32',
      )}
    >
      <ImageOff className="size-5" aria-hidden />
      {children}
    </div>
  )
}
