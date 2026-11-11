<script setup lang="ts">
import { Check, ChevronDown } from '@lucide/vue'
import {
  SelectContent, SelectItem, SelectItemIndicator, SelectItemText, SelectPortal,
  SelectRoot, SelectTrigger, SelectValue, SelectViewport,
} from 'reka-ui'

/**
 * A list to pick one value from, on Reka's Select.
 *
 * Not the native `<select>`: its open list cannot be styled, and on Windows it
 * draws in the system's colours whatever the product's are. Reka keeps what the
 * native one got right - typeahead, arrow keys, Escape, the focus returning to
 * the trigger - and announces itself as a combobox.
 *
 * The trigger takes the `id`, so a `<UiField>` label names it as it would an input,
 * and every other attribute - `aria-label`, `class`, `data-testid` - because the root
 * renders no element of its own to carry them.
 */
defineOptions({ inheritAttrs: false })

const model = defineModel<string>({ default: '' })

defineProps<{
  id?: string
  options: Array<{ value: string, label: string }>
  placeholder?: string
  disabled?: boolean
  invalid?: boolean
  describedBy?: string
}>()
</script>

<template>
  <SelectRoot v-model="model" :disabled="disabled">
    <SelectTrigger
      v-bind="$attrs"
      :id="id"
      :aria-describedby="describedBy"
      :aria-invalid="invalid || undefined"
      :class="cn(
        'flex h-control-md w-full items-center justify-between gap-2 rounded-control border border-border-input bg-surface px-3 text-left text-sm text-fg transition-colors focus:border-focus focus:outline-2 focus:outline-offset-0 focus:outline-focus disabled:cursor-not-allowed disabled:bg-surface-subtle disabled:opacity-70 data-placeholder:text-fg-muted',
        invalid && 'border-crit',
        $attrs.class as string,
      )"
    >
      <SelectValue :placeholder="placeholder" class="truncate" />
      <ChevronDown class="size-4 shrink-0 text-fg-muted" aria-hidden="true" />
    </SelectTrigger>

    <SelectPortal>
      <SelectContent
        position="popper"
        :side-offset="4"
        class="z-50 max-h-(--reka-select-content-available-height) min-w-(--reka-select-trigger-width) overflow-hidden rounded-control border border-border bg-surface shadow-raised"
      >
        <SelectViewport class="p-1">
          <SelectItem
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            class="relative flex h-control-sm cursor-default select-none items-center rounded-sm pr-8 pl-2.5 text-sm text-fg outline-none data-disabled:opacity-50 data-highlighted:bg-selected data-highlighted:text-fg"
          >
            <SelectItemText>{{ option.label }}</SelectItemText>
            <SelectItemIndicator class="absolute right-2 inline-flex items-center text-accent">
              <Check class="size-4" aria-hidden="true" />
            </SelectItemIndicator>
          </SelectItem>
        </SelectViewport>
      </SelectContent>
    </SelectPortal>
  </SelectRoot>
</template>
