<script setup lang="ts">
/**
 * Whether the live stream is actually connected.
 *
 * Small, but load-bearing during development: without it a page that has
 * silently lost its SSE connection is indistinguishable from one where nothing
 * has happened yet.
 */
const { connected } = useMercure()
const { t } = useI18n()
</script>

<template>
  <span class="inline-flex items-center gap-1.5 text-xs text-fg-muted" data-testid="realtime-indicator">
    <span
      class="size-2 rounded-full"
      :class="connected ? 'bg-success' : 'bg-warning animate-pulse'"
      aria-hidden="true"
    />
    {{ connected ? t('realtime.connected') : t('realtime.disconnected') }}
  </span>
</template>
