<script setup lang="ts">
const model = defineModel<string>({ default: '' })

defineProps<{
  id?: string
  options: Array<{ value: string, label: string }>
  disabled?: boolean
  describedBy?: string
}>()
</script>

<template>
  <select
    :id="id"
    v-model="model"
    :disabled="disabled"
    :aria-describedby="describedBy"
    class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-fg disabled:opacity-60"
  >
    <option v-for="option in options" :key="option.value" :value="option.value">
      {{ option.label }}
    </option>
  </select>
</template>
