<script setup lang="ts">
const model = defineModel<string>({ default: '' })

defineProps<{
  id?: string
  rows?: number
  placeholder?: string
  disabled?: boolean
  invalid?: boolean
  describedBy?: string
}>()
</script>

<template>
  <textarea
    :id="id"
    v-model="model"
    :rows="rows ?? 4"
    :placeholder="placeholder"
    :disabled="disabled"
    :aria-invalid="invalid || undefined"
    :aria-describedby="describedBy"
    :class="cn(
      'w-full rounded-control border border-border-input bg-surface px-3 py-2 text-sm text-fg transition-colors placeholder:text-fg-muted focus:border-focus focus:outline-2 focus:outline-offset-0 focus:outline-focus disabled:cursor-not-allowed disabled:bg-surface-subtle disabled:opacity-70',
      invalid && 'border-crit focus:border-crit focus:outline-crit',
    )"
  />
</template>
