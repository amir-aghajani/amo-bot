/** A set of change listeners: `subscribe` is what `useSyncExternalStore` takes, `notify` tells every listener. */
export function createListeners() {
  const listeners = new Set<() => void>()

  return {
    subscribe: (listener: () => void): (() => void) => {
      listeners.add(listener)
      return () => {
        listeners.delete(listener)
      }
    },
    notify: (): void => listeners.forEach((listener) => listener()),
  }
}
