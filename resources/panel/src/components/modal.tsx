import { createContext, useCallback, useContext, useEffect, useId, useRef, useState, useSyncExternalStore, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { IconButton } from '@/components/icon-button'
import { PortalContainerContext, useModalLayer } from '@/components/portal-container'
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
}

/** How wide it may grow from the layout's `md` up; below it, a phone's, it is a sheet the screen's whole width (.app-modal). */
const SIZES = { sm: 'md:max-w-[26rem]', md: 'md:max-w-lg', lg: 'md:max-w-2xl', xl: 'md:max-w-4xl' }

/** The dialog's fade-out (.app-modal in index.css), and a little more: what it showed stays on it meanwhile. */
const EXIT_MS = 200

/**
 * A centred dialog on the native <dialog> element, drawn as the Console's: the popover surface, 16px corners, a
 * title with its description under it and the close button at its end, then the content — no rules between them. On a
 * phone it is a sheet rising from the screen's bottom edge, the screen's whole width, and above the keyboard as it opens.
 * showModal() supplies the focus trap, Escape, inert background and top-layer stacking; the fade is CSS (.app-modal in
 * index.css), and what the dialog showed stays on it while it fades out — its owner has let go of the row by then —,
 * then leaves, so the next opening starts afresh. The content scrolls inside the panel, the header stays put. Floating
 * layers opened from inside (selects, menus, tooltips) portal into the dialog itself — the body is inert and below the
 * top layer while a modal is open — via PortalContainerContext, and the toasts are drawn in the topmost one. The ✕,
 * Escape and the backdrop ask first when a form inside has unsaved changes, and wait while a request inside runs
 * (`useHoldOpen`: the control that runs it says so).
 */
export function Modal({ open, onClose, title, description, children, size = 'md' }: ModalProps) {
  const ref = useRef<HTMLDialogElement>(null)
  const [container, setContainer] = useState<HTMLDialogElement | null>(null)
  const { scope, confirmDiscard } = useUnsavedScope()
  const [holds] = useState(createHolds)
  const held = useSyncExternalStore(holds.subscribe, holds.held)
  const shown = useShown(open, { title, description, children })
  const titleId = useId()
  const descriptionId = useId()

  const attach = useCallback((node: HTMLDialogElement | null) => {
    ref.current = node
    setContainer(node)
  }, [])

  const dismiss = () => {
    if (!holds.held()) confirmDiscard(onClose)
  }
  const handlers = useNativeDialog(ref, open, dismiss)
  useModalLayer(container, open)

  return (
    <dialog ref={attach} aria-labelledby={titleId} aria-describedby={shown?.description ? descriptionId : undefined} {...handlers} className={cn('app-modal text-popover-foreground', SIZES[size])}>
      <PortalContainerContext.Provider value={container}>
        <HoldsContext.Provider value={holds}>
          <UnsavedScope value={scope}>
            <div className="flex min-h-0 flex-1 flex-col">
              <div className="flex items-start gap-3 px-5 pt-5 pb-4">
                <div className="grid min-w-0 flex-1 gap-1">
                  <h2 id={titleId} className="text-subtitle font-semibold">
                    {shown?.title}
                  </h2>
                  {shown?.description && (
                    <p id={descriptionId} className="text-body text-muted-foreground">
                      {shown.description}
                    </p>
                  )}
                </div>
                <IconButton onClick={dismiss} disabled={held} aria-label="بستن" className="-me-1.5 -mt-0.5">
                  <X className="size-4" aria-hidden />
                </IconButton>
              </div>

              <div className="min-h-0 flex-1 scrollbar-thin overflow-y-auto px-5 pb-5">{shown?.children}</div>
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

/**
 * What the dialog shows: what it is handed while open, and — while it fades out — what it showed last, until the fade is
 * over (state adjusted during render while open, dropped by a timer once closed).
 */
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
  const listeners = new Set<() => void>()
  const changed = () => listeners.forEach((listener) => listener())

  return {
    subscribe: (listener: () => void) => {
      listeners.add(listener)
      return () => {
        listeners.delete(listener)
      }
    },
    held: () => count > 0,
    hold: () => {
      count++
      changed()
      return () => {
        count--
        changed()
      }
    },
  }
}

const HoldsContext = createContext<ReturnType<typeof createHolds> | null>(null)

/**
 * Keep the modal this is in from closing by its ✕, Escape or backdrop while `active` — a request it runs (a form's
 * submit, a confirmed decision, an operation, a batch). Outside a modal it holds nothing.
 */
export function useHoldOpen(active: boolean): void {
  const holds = useContext(HoldsContext)

  useEffect(() => (active && holds ? holds.hold() : undefined), [active, holds])
}

/** Not subscribed to anything: outside a modal nothing is held. */
const unsubscribed = () => () => undefined

/** Whether a request runs inside the modal this is in (`useHoldOpen`): another way out of what it shows (a picker's step back) waits as its ✕ does. */
export function useHeld(): boolean {
  const holds = useContext(HoldsContext)

  return useSyncExternalStore(holds?.subscribe ?? unsubscribed, () => holds?.held() ?? false)
}
