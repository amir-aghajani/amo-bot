import { createContext, useCallback, useContext, useEffect, useId, useRef, useState, useSyncExternalStore, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { IconButton } from '@/components/icon-button'
import { PortalContainerContext, useModalLayer } from '@/components/portal-container'
import { createListeners } from '@/lib/listeners'
import { PHONE, useMediaQuery } from '@/lib/use-media-query'
import { useNativeDialog } from '@/lib/use-native-dialog'
import { UnsavedScope, useUnsavedScope } from '@/lib/use-unsaved-guard'
import { cn } from '@/lib/utils'

interface ModalProps {
  open: boolean
  onClose: () => void
  title: ReactNode
  description?: ReactNode
  children: ReactNode
  size?: 'sm' | 'md' | 'lg' | 'xl'
  /**
   * Typing is the dialog's whole point (a text to write): its first field takes the focus on a phone too. Otherwise a
   * phone's dialog opens with the focus on itself, its keyboard down (useNativeDialog).
   */
  typing?: boolean
  /**
   * As tall as the screen gives whatever it shows, its content filling it: a form in tabs, each tab as long as its own,
   * the tabs and the actions where they stand whichever is shown.
   */
  tall?: boolean
}

/** The widest each size grows from `md` up; below it (a phone) the dialog is a full-width sheet (.app-modal). */
const SIZES = { sm: 'md:max-w-[26rem]', md: 'md:max-w-lg', lg: 'md:max-w-2xl', xl: 'md:max-w-4xl' }

/** The fade-out's length (.app-modal in index.css), plus a little: the content stays on the dialog meanwhile. */
const EXIT_MS = 200

/**
 * Every dialog of the panels, on the native <dialog> (showModal() brings the focus trap, Escape, the inert background
 * and the top layer). A title with its description, a ✕, then content that scrolls under a fixed header; on a phone a
 * sheet rising from the bottom edge, whose description scrolls with the content. Floating layers opened inside (selects, menus, tooltips) and the toasts portal into
 * the dialog, since the body is inert while it is open. The ✕, Escape and the backdrop ask before dropping unsaved
 * changes of a form inside, and wait while a request inside runs (`useHoldOpen`). While it fades out it keeps showing
 * what it showed, so its owner may let go of the row at once.
 */
export function Modal({ open, onClose, title, description, children, size = 'md', typing = false, tall = false }: ModalProps) {
  const ref = useRef<HTMLDialogElement>(null)
  const [container, setContainer] = useState<HTMLDialogElement | null>(null)
  const { scope, confirmDiscard } = useUnsavedScope()
  const [holds] = useState(createHolds)
  const held = useSyncExternalStore(holds.subscribe, holds.held)
  const shown = useShown(open, { title, description, children })
  const titleId = useId()
  const descriptionId = useId()
  // On a phone the head that stays is the title and the ✕ alone: the description (a gateway's, several lines) scrolls
  // with the body, rather than keep a third of a short screen from it.
  const phone = useMediaQuery(PHONE)
  const described = shown?.description && (
    <p id={descriptionId} className={cn('text-body text-muted-foreground', phone && 'pb-4')}>
      {shown.description}
    </p>
  )

  const attach = useCallback((node: HTMLDialogElement | null) => {
    ref.current = node
    setContainer(node)
  }, [])

  const dismiss = () => {
    if (!holds.held()) confirmDiscard(onClose)
  }
  const handlers = useNativeDialog(ref, open, dismiss, { typing })
  useModalLayer(container, open)

  return (
    <dialog
      ref={attach}
      tabIndex={-1}
      aria-labelledby={titleId}
      aria-describedby={shown?.description ? descriptionId : undefined}
      {...handlers}
      // Tall: the screen's height, down to the dialog's max-height (.app-modal).
      className={cn('app-modal text-popover-foreground outline-none', SIZES[size], tall && 'h-full')}
    >
      <PortalContainerContext.Provider value={container}>
        <HoldsContext.Provider value={holds}>
          <UnsavedScope value={scope}>
            <div className="flex min-h-0 flex-1 flex-col">
              <div className="flex items-start gap-3 px-5 pt-5 pb-4">
                <div className="grid min-w-0 flex-1 gap-1">
                  <h2 id={titleId} className="text-subtitle font-semibold">
                    {shown?.title}
                  </h2>
                  {!phone && described}
                </div>
                {/* A finger's 44px on a phone, the icon where it stands. */}
                <IconButton onClick={dismiss} disabled={held} aria-label="بستن" className="-me-1.5 -mt-0.5 max-md:-me-3 max-md:-mt-2 max-md:size-11">
                  <X className="size-4" aria-hidden />
                </IconButton>
              </div>

              {/* Tall, a column: the content (a form's `grow`) fills what the description leaves. */}
              <div className={cn('min-h-0 flex-1 scrollbar-thin overflow-y-auto px-5 pb-5', tall && 'flex flex-col')}>
                {phone && described}
                {shown?.children}
              </div>
            </div>
          </UnsavedScope>
        </HoldsContext.Provider>
      </PortalContainerContext.Provider>
    </dialog>
  )
}

interface Content {
  title: ReactNode
  description: ReactNode
  children: ReactNode
}

/** What the dialog shows: its content while open, and the last content until the fade-out is over. */
function useShown(open: boolean, content: Content): Content | null {
  const [last, setLast] = useState<Content | null>(open ? content : null)
  if (open && (last === null || last.title !== content.title || last.description !== content.description || last.children !== content.children)) {
    setLast(content)
  }

  useEffect(() => {
    if (open || last === null) return
    const timer = window.setTimeout(() => setLast(null), EXIT_MS)
    return () => window.clearTimeout(timer)
  }, [open, last])

  return open ? content : last
}

/** The requests running inside one modal: while there is one, its ✕, Escape and backdrop wait. */
function createHolds() {
  let count = 0
  const changes = createListeners()

  return {
    subscribe: changes.subscribe,
    held: () => count > 0,
    hold: () => {
      count++
      changes.notify()
      return () => {
        count--
        changes.notify()
      }
    },
  }
}

const HoldsContext = createContext<ReturnType<typeof createHolds> | null>(null)

/** Keep the surrounding modal open (its ✕, Escape, backdrop) while `active`: a request it runs. Nothing outside a modal. */
export function useHoldOpen(active: boolean): void {
  const holds = useContext(HoldsContext)

  useEffect(() => (active && holds ? holds.hold() : undefined), [active, holds])
}

const unsubscribed = () => () => undefined

/** Whether a request runs inside the surrounding modal: another way out (a picker's step back) waits as the ✕ does. */
export function useHeld(): boolean {
  const holds = useContext(HoldsContext)

  return useSyncExternalStore(holds?.subscribe ?? unsubscribed, () => holds?.held() ?? false)
}
