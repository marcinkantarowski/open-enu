<script setup lang="ts">
import { CircleCheck, CircleX, Info, X } from '@lucide/vue'

/**
 * Mounted once, in the layout. Anything can raise a toast from anywhere because
 * the queue is module-scoped, not passed down through props.
 */
const { toasts, dismiss } = useToast()
const { t } = useI18n()

const tones = {
  info: { icon: Info, class: 'border-border', iconClass: 'text-info' },
  success: { icon: CircleCheck, class: 'border-ok-border', iconClass: 'text-ok' },
  error: { icon: CircleX, class: 'border-crit-border', iconClass: 'text-crit' },
}
</script>

<template>
  <!-- `polite`, not `assertive`: a success toast should not interrupt whatever
       a screen-reader user is in the middle of reading. -->
  <div
    class="pointer-events-none fixed right-4 bottom-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2"
    role="status"
    aria-live="polite"
  >
    <div
      v-for="toast in toasts"
      :key="toast.id"
      :class="cn('pointer-events-auto flex items-start gap-3 rounded-control border bg-surface px-4 py-3 text-sm shadow-raised', tones[toast.tone].class)"
      data-testid="toast"
    >
      <component :is="tones[toast.tone].icon" :class="cn('mt-px size-5 shrink-0', tones[toast.tone].iconClass)" aria-hidden="true" />
      <span class="flex-1 text-fg">{{ toast.message }}</span>
      <button
        type="button"
        class="-m-1 rounded-sm p-1 text-fg-muted hover:bg-surface-subtle hover:text-fg"
        :aria-label="t('common.close')"
        @click="dismiss(toast.id)"
      >
        <X class="size-4" aria-hidden="true" />
      </button>
    </div>
  </div>
</template>
