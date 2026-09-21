<script setup lang="ts">
// `number` as well as `string`: a numeric field binds with `v-model.number`,
// and a string-only model makes that a type error at every call site.
const model = defineModel<string | number>({ default: '' })

defineProps<{
  id?: string
  type?: string
  placeholder?: string
  autocomplete?: string
  disabled?: boolean
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
    :disabled="disabled"
    :aria-invalid="invalid || undefined"
    :aria-describedby="describedBy"
    class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-fg placeholder:text-fg-muted disabled:opacity-60"
    :class="invalid ? 'border-danger' : ''"
  >
</template>
