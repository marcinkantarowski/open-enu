<script setup lang="ts">
import { Check, Minus } from '@lucide/vue'
import { CheckboxIndicator, CheckboxRoot } from 'reka-ui'

/**
 * A checkbox with its label. The label is part of the control - clicking the
 * words ticks the box - so a 16 px square is not the whole target.
 */
const model = defineModel<boolean | 'indeterminate'>({ default: false })

defineProps<{
  label: string
  description?: string
  disabled?: boolean
}>()

const id = useId()
</script>

<template>
  <div class="flex items-start gap-2.5">
    <CheckboxRoot
      :id="id"
      v-model="model"
      :disabled="disabled"
      :aria-describedby="description ? `${id}-description` : undefined"
      class="mt-0.5 flex size-4.5 shrink-0 items-center justify-center rounded-sm border border-border-input bg-surface text-accent-fg transition-colors data-[state=checked]:border-accent data-[state=checked]:bg-accent data-[state=indeterminate]:border-accent data-[state=indeterminate]:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
    >
      <CheckboxIndicator>
        <Minus v-if="model === 'indeterminate'" class="size-3.5" :stroke-width="3" aria-hidden="true" />
        <Check v-else class="size-3.5" :stroke-width="3" aria-hidden="true" />
      </CheckboxIndicator>
    </CheckboxRoot>
    <div class="flex flex-col gap-0.5">
      <label :for="id" class="text-sm text-fg">{{ label }}</label>
      <p v-if="description" :id="`${id}-description`" class="text-xs text-fg-muted">{{ description }}</p>
    </div>
  </div>
</template>
