import { useEffect, useLayoutEffect, useRef, useState, type KeyboardEvent } from 'react'
import { Paperclip, SendHorizontal, X } from 'lucide-react'
import { fileOf, PICTURE_TYPES, usePicture } from '@/apps/app/parts/picture'
import { tap } from '@/apps/app/telegram/haptics'
import { cn } from '@/lib/utils'

/*
 * The message box at the bottom of a conversation, as a messenger has it: a picture to attach (shown small with a way to
 * drop it before it goes), the words in a box that grows with them, and the send button — which keeps the keyboard up,
 * so the next message is typed straight on. It stays above the phone's edge and above the keyboard: the page resizes for
 * the keyboard where the browser can (index.html's interactive-widget), and where it cannot (an iPhone's) the box rises
 * by what the keyboard covers of the page. Its height is told to the screen, which leaves that room under the messages.
 */

/** The longest message the shop takes (Support\Services\Tickets::BODY_MAX). */
const BODY_MAX = 4000

/** The most lines the box grows to before it scrolls. */
const MOST_HEIGHT_PX = 140

/** How much of the page's bottom the keyboard covers where the page is not resized for it (0 where it is, or none is up). */
function useKeyboardInset(): number {
  const [inset, setInset] = useState(0)
  useEffect(() => {
    const viewport = window.visualViewport
    if (viewport === null || viewport === undefined) return
    const measure = () => setInset(Math.max(0, Math.round(window.innerHeight - viewport.height - viewport.offsetTop)))
    viewport.addEventListener('resize', measure)
    viewport.addEventListener('scroll', measure)
    measure()

    return () => {
      viewport.removeEventListener('resize', measure)
      viewport.removeEventListener('scroll', measure)
    }
  }, [])

  return inset
}

/** Whether Enter sends (a keyboard and mouse) or breaks the line (a phone's on-screen keyboard). */
function enterSends(): boolean {
  return window.matchMedia('(pointer: fine)').matches
}

interface ComposerProps {
  onSend: (body: string, file: Blob | null) => void
  placeholder: string
  /** Its height changed: the room the screen leaves under the messages. */
  onHeight: (height: number) => void
}

export function Composer({ onSend, placeholder, onHeight }: ComposerProps) {
  const [text, setText] = useState('')
  const picture = usePicture()
  const box = useRef<HTMLTextAreaElement>(null)
  const picker = useRef<HTMLInputElement>(null)
  const bar = useRef<HTMLDivElement>(null)
  const inset = useKeyboardInset()
  const ready = text.trim() !== ''

  // The box as tall as its words, up to a few lines.
  useLayoutEffect(() => {
    const element = box.current
    if (element === null) return
    element.style.height = 'auto'
    if (text !== '') element.style.height = `${Math.min(element.scrollHeight, MOST_HEIGHT_PX)}px`
  }, [text])

  useEffect(() => {
    const element = bar.current
    if (element === null) return
    onHeight(element.offsetHeight)
    if (typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(() => onHeight(element.offsetHeight))
    observer.observe(element)

    return () => observer.disconnect()
  }, [onHeight])

  const send = () => {
    if (!ready) return
    tap('medium')
    onSend(text.trim(), picture.picked?.file ?? null)
    setText('')
    picture.clear()
    box.current?.focus()
  }

  const keyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing && enterSends()) {
      event.preventDefault()
      send()
    }
  }

  return (
    <div
      ref={bar}
      // Its hairline on top drawn as a list's is (app.css list-row): 1px, halved.
      className="glass before:bg-separator fixed inset-x-0 z-30 before:absolute before:inset-x-0 before:top-0 before:h-px before:origin-top before:scale-y-50"
      style={{ bottom: inset, paddingBottom: inset > 0 ? '8px' : 'calc(var(--app-bottom) + 8px)' }}
    >
      <div className="mx-auto flex max-w-xl flex-col gap-2 px-3 pt-2">
        {picture.picked !== null && (
          <div className="animate-rise rounded-control bg-section flex items-center gap-3 p-2 shadow-card">
            {/* 8px in from the box's 14px corners: 6px round. */}
            <img src={picture.picked.preview} alt="تصویر انتخاب‌شده" className="size-12 shrink-0 rounded-md object-cover" />
            <span className="flex min-w-0 flex-1 flex-col">
              <span className="text-footnote font-semibold">تصویر پیوست</span>
              <span className="text-hint text-caption">{ready ? 'با این پیام فرستاده می‌شود' : 'چند کلمه هم برای تصویر بنویسید'}</span>
            </span>
            <button
              type="button"
              onClick={() => {
                tap()
                picture.clear()
              }}
              aria-label="حذف تصویر"
              className="press tap-target text-hint relative grid size-9 shrink-0 place-items-center rounded-full bg-fill"
            >
              <X aria-hidden className="size-4" />
            </button>
          </div>
        )}
        {picture.problem !== null && (
          <p className="px-3 text-caption text-danger" role="alert">
            {picture.problem}
          </p>
        )}
        {/* Three things 44px high — the attach button, the box (its first line), the send button —, the buttons' icons 20px at their centres. */}
        <div className="flex items-end gap-2">
          <button
            type="button"
            onClick={() => {
              tap()
              picker.current?.click()
            }}
            aria-label="پیوست تصویر"
            className="press text-hint grid size-11 shrink-0 place-items-center rounded-full active:bg-fill"
          >
            <Paperclip aria-hidden className="size-5" />
          </button>
          <input
            ref={picker}
            type="file"
            accept={PICTURE_TYPES}
            aria-label="انتخاب تصویر"
            hidden
            onChange={(event) => {
              const file = fileOf(event)
              if (file !== undefined) void picture.pick(file)
            }}
          />
          <textarea
            ref={box}
            rows={1}
            value={text}
            maxLength={BODY_MAX}
            onChange={(event) => setText(event.target.value)}
            onKeyDown={keyDown}
            placeholder={placeholder}
            aria-label="متن پیام"
            className="placeholder:text-hint/80 focus:ring-accent/40 text-text min-h-11 flex-1 resize-none rounded-[22px] bg-fill px-4 py-2.5 text-body outline-none [unicode-bidi:plaintext] focus:ring-2"
          />
          <button
            type="button"
            // Pressed, it leaves the focus in the box: the keyboard stays up for the next message.
            onMouseDown={(event) => event.preventDefault()}
            onClick={send}
            disabled={!ready}
            aria-label="ارسال"
            className={cn('press grid size-11 shrink-0 place-items-center rounded-full transition-colors', ready ? 'bg-accent text-on-accent' : 'text-hint bg-fill')}
          >
            {/* The arrow points the way the app reads: to the left. */}
            <SendHorizontal aria-hidden className="size-5 -scale-x-100" />
          </button>
        </div>
      </div>
    </div>
  )
}
