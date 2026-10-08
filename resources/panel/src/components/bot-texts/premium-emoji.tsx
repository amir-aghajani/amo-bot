import { useEffect, useRef, useState, type CSSProperties } from 'react'
import type { AnimationItem, LottiePlayer } from 'lottie-web'
import { useCustomEmoji } from '@/components/bot-texts/custom-emojis'
import { api, mediaUrl } from '@/lib/api'
import { REDUCED_MOTION, useMediaQuery } from '@/lib/use-media-query'
import { cn } from '@/lib/utils'

/** Telegram's still picture of a premium emoji, through the panel's API (the browser keeps it for a day). */
function premiumEmojiUrl(id: string): string {
  return mediaUrl(`/bot/custom-emojis/${encodeURIComponent(id)}/image`)
}

/** How a premium emoji moves — its Lottie animation (Telegram's gzipped .tgs) or its WebM video —: its address under the panel's API. */
function animationPath(id: string): string {
  return `/bot/custom-emojis/${encodeURIComponent(id)}/animation`
}

/** Sized like the text around it, as Telegram sets an emoji in a line. */
const BOX = 'inline-block size-[1.25em] align-[-0.25em]'

interface PremiumEmojiProps {
  id: string
  /** The plain emoji it stands for, shown when there is no picture. */
  fallback: string
  className?: string
}

/**
 * A premium emoji as the panel shows it — as Telegram draws it: moving when it is an animated (Lottie) or a video one,
 * while it is on screen and unless the system asks for reduced motion; in the colour of the text around it when Telegram
 * paints it so (`repaint`); otherwise Telegram's still picture of it — or the plain emoji it stands for when there is no
 * picture (Telegram cannot be asked, the id is unknown), as Telegram itself does where a premium emoji cannot be shown.
 * How it is drawn comes from the kept list (/emoji); one the list does not keep shows its still picture.
 */
export function PremiumEmoji({ id, fallback, className }: PremiumEmojiProps) {
  const kept = useCustomEmoji(id)
  const moving = !useMediaQuery(REDUCED_MOTION)
  const repaint = kept?.repaint ?? false

  if (moving && kept?.format === 'animated' && canUnzip()) {
    return <LottieEmoji key={id} id={id} fallback={fallback} repaint={repaint} className={className} />
  }
  // A video cannot be painted: one Telegram repaints keeps its still picture, in the text's colour.
  if (moving && kept?.format === 'video' && !repaint && canPlayWebm()) {
    return <VideoEmoji key={id} id={id} fallback={fallback} className={className} />
  }

  return <StillEmoji id={id} fallback={fallback} repaint={repaint} className={className} />
}

/** The still picture — for a repainted one, its shape (a mask) filled with the text's colour. */
function StillEmoji({ id, fallback, repaint, className }: PremiumEmojiProps & { repaint: boolean }) {
  // Keyed on the id: a text edited to another emoji tries its picture afresh.
  const [failedId, setFailedId] = useState<string | null>(null)
  const src = premiumEmojiUrl(id)

  // A mask reports nothing when its picture does not come, so a repainted one loads it on the side to know.
  useEffect(() => {
    if (!repaint) return
    const probe = new Image()
    const failed = () => setFailedId(id)
    probe.addEventListener('error', failed)
    probe.src = src
    return () => probe.removeEventListener('error', failed)
  }, [repaint, id, src])

  if (failedId === id) {
    return <span className={className}>{fallback}</span>
  }
  if (repaint) {
    return <span role="img" aria-label={fallback} className={cn(BOX, 'bg-current', className)} style={maskOf(src)} />
  }

  return <img src={src} alt={fallback} loading="lazy" draggable={false} onError={() => setFailedId(id)} className={cn(BOX, 'object-contain', className)} />
}

const maskOf = (src: string): CSSProperties => ({
  maskImage: `url("${src}")`,
  maskSize: 'contain',
  maskRepeat: 'no-repeat',
  maskPosition: 'center',
  WebkitMaskImage: `url("${src}")`,
  WebkitMaskSize: 'contain',
  WebkitMaskRepeat: 'no-repeat',
  WebkitMaskPosition: 'center',
})

/**
 * An animated one: its still picture until the animation is ready, then the animation — loaded the first time it comes
 * on screen, playing only while it is there (a picker of a hundred plays the dozen in sight). The Lottie player is
 * fetched with the first one; a repainted one has every fill and stroke in the text's colour (`.emoji-repaint`).
 */
function LottieEmoji({ id, fallback, repaint, className }: PremiumEmojiProps & { repaint: boolean }) {
  const box = useRef<HTMLSpanElement>(null)
  const stage = useRef<HTMLSpanElement>(null)
  const [playing, setPlaying] = useState(false)

  useEffect(() => {
    const element = box.current
    const container = stage.current
    if (!element || !container) return
    let animation: AnimationItem | null = null
    let started = false
    let visible = false
    let gone = false

    const start = async () => {
      try {
        const [lottie, data] = await Promise.all([lottiePlayer(), lottieOf(id)])
        if (gone) return
        animation = lottie.loadAnimation({ container, renderer: 'svg', loop: true, autoplay: visible, animationData: structuredClone(data) })
        animation.addEventListener('DOMLoaded', () => setPlaying(true))
      } catch {
        // No animation to be had: the still picture stays.
      }
    }
    const observer = new IntersectionObserver(([entry]) => {
      visible = entry?.isIntersecting ?? false
      if (animation) {
        if (visible) animation.play()
        else animation.pause()
      } else if (visible && !started) {
        started = true
        void start()
      }
    })
    observer.observe(element)

    return () => {
      gone = true
      observer.disconnect()
      animation?.destroy()
    }
  }, [id])

  return (
    <span ref={box} role="img" aria-label={fallback} className={cn(BOX, 'relative', repaint && 'emoji-repaint', className)}>
      {!playing && <StillEmoji id={id} fallback={fallback} repaint={repaint} className="absolute inset-0 size-full" />}
      <span ref={stage} aria-hidden className="absolute inset-0" />
    </span>
  )
}

/** A video one: its WebM, looping and muted, over its still picture until it plays; the still one when it cannot. */
function VideoEmoji({ id, fallback, className }: PremiumEmojiProps) {
  const [failed, setFailed] = useState(false)

  if (failed) {
    return <StillEmoji id={id} fallback={fallback} repaint={false} className={className} />
  }

  return <LoopingVideo id={id} fallback={fallback} onError={() => setFailed(true)} className={className} />
}

/**
 * The video itself: nothing of it fetched until it first comes on screen (`preload="none"`, its still picture as the
 * poster), playing only while it is there — a picker of a hundred plays the dozen in sight —, as the Lottie ones do.
 */
function LoopingVideo({ id, fallback, onError, className }: PremiumEmojiProps & { onError: () => void }) {
  const video = useRef<HTMLVideoElement>(null)

  useEffect(() => {
    const element = video.current
    if (!element) return
    const observer = new IntersectionObserver(([entry]) => {
      // A play the browser turns down (it never does for a muted one) leaves the poster: nothing to say.
      if (entry?.isIntersecting) element.play().catch(() => undefined)
      else element.pause()
    })
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  return (
    <video
      ref={video}
      src={mediaUrl(animationPath(id))}
      poster={premiumEmojiUrl(id)}
      preload="none"
      loop
      muted
      playsInline
      disablePictureInPicture
      aria-label={fallback}
      onError={onError}
      className={cn(BOX, 'object-contain', className)}
    />
  )
}

/** The Lottie player (lottie-web's light build: SVG, no expressions — what Telegram's .tgs use), fetched once, when first needed. */
let player: Promise<LottiePlayer> | null = null
function lottiePlayer(): Promise<LottiePlayer> {
  player ??= import('lottie-web/build/player/lottie_light').then(
    (module) => module.default,
    (error: unknown) => {
      player = null
      throw error
    },
  )
  return player
}

/** Each animation's JSON — the .tgs unzipped in the browser — fetched once and shared by every place that shows it. */
const animations = new Map<string, Promise<unknown>>()
function lottieOf(id: string): Promise<unknown> {
  let data = animations.get(id)
  if (!data) {
    data = api.blob(animationPath(id)).then((tgs) => new Response(tgs.stream().pipeThrough(new DecompressionStream('gzip'))).json())
    data.catch(() => animations.delete(id))
    animations.set(id, data)
  }
  return data
}

const canUnzip = () => typeof DecompressionStream !== 'undefined'

let webm: boolean | undefined
const canPlayWebm = () => (webm ??= document.createElement('video').canPlayType('video/webm; codecs="vp9"') !== '')
