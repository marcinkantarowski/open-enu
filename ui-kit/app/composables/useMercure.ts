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

  function subscribe(listener: Listener): () => void {
    listeners.add(listener)
    void connect()

    const stop = () => {
      listeners.delete(listener)
      // Last listener out closes the stream, so a background tab is not holding
      // a connection open for nothing.
      if (listeners.size === 0) disconnect()
    }

    // Tied to the component's scope when there is one, so navigating away
    // cleans up without every caller remembering to.
    if (getCurrentScope()) onScopeDispose(stop)

    return stop
  }

  return { connect, disconnect, subscribe, connected }
}
