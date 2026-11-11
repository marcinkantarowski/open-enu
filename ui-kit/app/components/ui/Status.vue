<script setup lang="ts">
import { Circle, CircleCheck, CircleX, Info, TriangleExclamationPoint } from '@lucide/vue'

/**
 * A status: icon, colour and a word - always all three.
 *
 * Colour alone fails one reader in twelve and every printout; an icon alone is a
 * guessing game. The word is the slot, and it is the status's own name ("Paid",
 * "Overdue"), never "OK". The tones are fixed: a tenant's brand never recolours
 * what "overdue" looks like.
 */
const props = withDefaults(defineProps<{ tone?: 'ok' | 'warn' | 'crit' | 'neutral' | 'info' }>(), {
  tone: 'neutral',
})

const tones = {
  ok: { icon: CircleCheck, class: 'border-ok-border bg-ok-soft text-ok' },
  warn: { icon: TriangleExclamationPoint, class: 'border-warn-border bg-warn-soft text-warn' },
  crit: { icon: CircleX, class: 'border-crit-border bg-crit-soft text-crit' },
  info: { icon: Info, class: 'border-info-border bg-info-soft text-info' },
  neutral: { icon: Circle, class: 'border-border bg-surface-subtle text-fg-muted' },
}
</script>

<template>
  <span
    :class="cn('inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-sm border px-1.5 text-xs font-medium', tones[props.tone].class)"
    :data-tone="props.tone"
  >
    <component :is="tones[props.tone].icon" class="size-3.5 shrink-0" aria-hidden="true" />
    <slot />
  </span>
</template>
