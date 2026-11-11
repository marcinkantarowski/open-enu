<script setup lang="ts">
const props = defineProps<{ percent: number, label?: string, tone?: 'accent' | 'crit' | 'ok' }>()

const tones: Record<string, string> = {
  accent: 'bg-accent',
  crit: 'bg-crit',
  ok: 'bg-ok',
}
</script>

<template>
  <div class="flex flex-col gap-1">
    <div
      class="h-2 w-full overflow-hidden rounded-full bg-surface-subtle"
      role="progressbar"
      :aria-valuenow="Math.round(props.percent)"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-label="props.label"
    >
      <div
        class="h-full rounded-full transition-[width] duration-300"
        :class="tones[props.tone ?? 'accent']"
        :style="{ width: `${Math.min(100, Math.max(0, props.percent))}%` }"
      />
    </div>
    <p v-if="props.label" class="text-xs text-fg-muted">{{ props.label }}</p>
  </div>
</template>
