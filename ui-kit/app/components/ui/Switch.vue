<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from 'reka-ui'

/**
 * On or off, taking effect at once - a setting, not a form answer. Something
 * that waits for a Save button is a checkbox.
 */
const model = defineModel<boolean>({ default: false })

defineProps<{
  label: string
  description?: string
  disabled?: boolean
}>()

const id = useId()
</script>

<template>
  <div class="flex items-start justify-between gap-4">
    <div class="flex flex-col gap-0.5">
      <label :for="id" class="text-sm text-fg">{{ label }}</label>
      <p v-if="description" :id="`${id}-description`" class="text-xs text-fg-muted">{{ description }}</p>
    </div>
    <SwitchRoot
      :id="id"
      v-model="model"
      :disabled="disabled"
      :aria-describedby="description ? `${id}-description` : undefined"
      class="relative inline-flex h-6 w-10 shrink-0 items-center rounded-full border-2 border-transparent bg-border-input transition-colors data-[state=checked]:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
    >
      <SwitchThumb class="block size-5 rounded-full bg-white shadow-card transition-transform data-[state=checked]:translate-x-4" />
    </SwitchRoot>
  </div>
</template>
