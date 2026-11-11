<script setup lang="ts">
import { RadioGroupIndicator, RadioGroupItem, RadioGroupRoot } from 'reka-ui'

/**
 * One choice out of a few that should all be visible at once. Arrow keys move
 * between the options and Tab leaves the group, as a screen reader expects.
 */
const model = defineModel<string>({ default: '' })

defineProps<{
  label: string
  options: Array<{ value: string, label: string, description?: string }>
  orientation?: 'vertical' | 'horizontal'
  disabled?: boolean
}>()

const id = useId()
</script>

<template>
  <RadioGroupRoot
    v-model="model"
    :disabled="disabled"
    :orientation="orientation ?? 'vertical'"
    :aria-labelledby="`${id}-label`"
    class="flex flex-col gap-2"
  >
    <span :id="`${id}-label`" class="text-sm font-medium text-fg">{{ label }}</span>
    <div :class="orientation === 'horizontal' ? 'flex flex-wrap gap-5' : 'flex flex-col gap-2.5'">
      <div v-for="option in options" :key="option.value" class="flex items-start gap-2.5">
        <RadioGroupItem
          :id="`${id}-${option.value}`"
          :value="option.value"
          class="mt-0.5 flex size-4.5 shrink-0 items-center justify-center rounded-full border border-border-input bg-surface data-[state=checked]:border-accent disabled:cursor-not-allowed disabled:opacity-50"
        >
          <RadioGroupIndicator class="size-2.5 rounded-full bg-accent" />
        </RadioGroupItem>
        <div class="flex flex-col gap-0.5">
          <label :for="`${id}-${option.value}`" class="text-sm text-fg">{{ option.label }}</label>
          <p v-if="option.description" class="text-xs text-fg-muted">{{ option.description }}</p>
        </div>
      </div>
    </div>
  </RadioGroupRoot>
</template>
