<script setup lang="ts">
/**
 * A label that is not a status: "Overridden", "Impersonated", a category. No
 * icon, so it is never mistaken for one. Something with a state uses UiStatus.
 */
const props = withDefaults(defineProps<{ tone?: 'neutral' | 'accent' }>(), { tone: 'neutral' })

const tones: Record<string, string> = {
  neutral: 'border-border bg-surface-subtle text-fg-muted',
  accent: 'border-accent-200 bg-selected text-accent-700',
}
</script>

<template>
  <span :class="cn('inline-flex h-6 items-center whitespace-nowrap rounded-sm border px-1.5 text-xs font-medium', tones[props.tone])">
    <slot />
  </span>
</template>
