<script setup lang="ts">
import { CircleCheck, CircleX, Info, TriangleExclamationPoint } from '@lucide/vue'

/**
 * Something the reader must know before going on, in place on the page. Says
 * what happened and what to do about it; a toast is for what can be missed.
 */
const props = withDefaults(defineProps<{ tone?: 'info' | 'ok' | 'warn' | 'crit', title?: string }>(), {
  tone: 'info',
  title: undefined,
})

const tones = {
  info: { icon: Info, class: 'border-info-border bg-info-soft', iconClass: 'text-info' },
  ok: { icon: CircleCheck, class: 'border-ok-border bg-ok-soft', iconClass: 'text-ok' },
  warn: { icon: TriangleExclamationPoint, class: 'border-warn-border bg-warn-soft', iconClass: 'text-warn' },
  crit: { icon: CircleX, class: 'border-crit-border bg-crit-soft', iconClass: 'text-crit' },
}
</script>

<template>
  <!-- `alert` for the two tones someone must not miss; `status` for the rest,
       which announce politely instead of interrupting. -->
  <div
    :class="cn('flex gap-3 rounded-control border px-4 py-3 text-sm text-fg', tones[props.tone].class)"
    :role="props.tone === 'crit' || props.tone === 'warn' ? 'alert' : 'status'"
  >
    <component :is="tones[props.tone].icon" :class="cn('mt-px size-5 shrink-0', tones[props.tone].iconClass)" aria-hidden="true" />
    <div class="flex min-w-0 flex-col gap-1">
      <p v-if="props.title" class="font-semibold">{{ props.title }}</p>
      <div><slot /></div>
    </div>
  </div>
</template>
