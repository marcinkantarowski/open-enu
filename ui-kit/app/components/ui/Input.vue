<script setup lang="ts">
// `number` as well as `string`: a numeric field binds with `v-model.number`,
// and a string-only model makes that a type error at every call site.
const model = defineModel<string | number>({ default: '' })

defineProps<{
  id?: string
  type?: string
  placeholder?: string
  autocomplete?: string
  inputmode?: 'text' | 'numeric' | 'decimal' | 'tel' | 'email' | 'url' | 'search' | 'none'
  disabled?: boolean
  readonly?: boolean
  invalid?: boolean
  describedBy?: string
}>()
</script>

<template>
  <input
    :id="id"
    v-model="model"
    :type="type ?? 'text'"
    :placeholder="placeholder"
    :autocomplete="autocomplete"
    :inputmode="inputmode"
    :disabled="disabled"
    :readonly="readonly"
    :aria-invalid="invalid || undefined"
    :aria-describedby="describedBy"
    :class="cn(
      'h-control-md w-full rounded-control border border-border-input bg-surface px-3 text-sm text-fg transition-colors placeholder:text-fg-muted focus:border-focus focus:outline-2 focus:outline-offset-0 focus:outline-focus disabled:cursor-not-allowed disabled:bg-surface-subtle disabled:opacity-70 read-only:bg-surface-subtle',
      invalid && 'border-crit focus:border-crit focus:outline-crit',
    )"
  >
</template>
