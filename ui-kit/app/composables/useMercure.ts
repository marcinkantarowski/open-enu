import { getCurrentScope, onScopeDispose, ref } from 'vue'

/**
 * One SSE connection per tab, shared by everything that listens.
 *
 * EventSource cannot set headers, so the subscriber credential is a cookie the
 * API issues - which is why connecting starts with a POST rather than simply
 * opening the stream.
 *
 * The connection is module-scoped rather than per-component: a page with six
 * live widgets would otherwise open six streams, and browsers cap concurrent
 * connections per host at around six.
 */
type Listener = (payload: RealtimeMessage) => void

export interface RealtimeMessage {
  event: string
  subjectId: string
  occurredAt: string
  payload: Record<string, unknown>
}

let source: EventSource | null = null
let connecting: Promise<void> | null = null
const listeners = new Set<Listener>()
const connected = ref(false)
// Holders keep the stream open without listening for anything - the app shell,
// which shows whether the tab is live on every page, including the ones that
// subscribe to nothing.
let holders = 0

export function useMercure() {
  const config = useRuntimeConfig()
  const api = useApi()

  async function connect(): Promise<void> {
    if (source || import.meta.server) return

    connecting ??= (async () => {
      try {
        // Sets the httpOnly `mercureAuthorization` cookie, scoped to this
        // tenant's topics only - a subscriber token for `*` would receive every
        // tenant's updates.
        const { topic } = await api.post<{ topic: string }>('/api/realtime/token')

        const url = new URL(String(config.public.mercureUrl))
        url.searchParams.append('topic', topic)

        source = new EventSource(url.toString(), { withCredentials: true })

        source.onopen = () => { connected.value = true }

        source.onmessage = (event) => {
          try {
            const message = JSON.parse(event.data) as RealtimeMessage
            for (const listener of listeners) listener(message)
          } catch {
            // A malformed frame must not take down the stream for everyone.
          }
        }

        source.onerror = () => {
          connected.value = false
          // EventSource reconnects on its own with backoff. Closing here would
          // replace that with nothing.
        }
      } finally {
        connecting = null
      }
    })()

    return connecting
  }

  function disconnect() {
    source?.close()
    source = null
    connected.value = false
  }

  // Nobody left who wants the stream: close it, so a tab is not holding a
  // connection open for nothing.
  function releaseIfUnused() {
    if (listeners.size === 0 && holders === 0) disconnect()
  }

  function subscribe(listener: Listener): () => void {
    listeners.add(listener)
    void connect()

    const stop = () => {
      listeners.delete(listener)
      releaseIfUnused()
    }

    // Tied to the component's scope when there is one, so navigating away
    // cleans up without every caller remembering to.
    if (getCurrentScope()) onScopeDispose(stop)

    return stop
  }

  /**
   * Keep the stream open until the returned function is called.
   *
   * The shell holds it for as long as it is mounted. Before this the shell only
   * called `connect()`, so the first page that subscribed and was then left
   * closed the shell's stream with it - and the header said "Reconnecting..."
   * on every page after, while nothing was reconnecting at all.
   */
  function hold(): () => void {
    holders++
    void connect()

    let released = false

    return () => {
      if (released) return
      released = true
      holders--
      releaseIfUnused()
    }
  }

  return { connect, disconnect, subscribe, hold, connected }
}
