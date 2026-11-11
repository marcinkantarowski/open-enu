<script setup lang="ts">
import { X } from '@lucide/vue'
import {
  DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle,
} from 'reka-ui'

/**
 * A decision that blocks the page until it is made, on Reka's Dialog.
 *
 * Reka traps focus, closes on Escape, makes the page behind it inert and gives the
 * focus back to whatever opened it - the parts hand-rolled modals get wrong, and
 * all of them accessibility requirements rather than polish. The title is
 * required: it is the dialog's accessible name.
 *
 * Something to read or edit alongside the page, without blocking it, is a UiDrawer.
 */
const open = defineModel<boolean>('open', { default: false })

const props = withDefaults(defineProps<{
  title: string
  description?: string
  size?: 'sm' | 'md' | 'lg'
}>(), {
  description: undefined,
  size: 'md',
})

const { t } = useI18n()

const sizes: Record<string, string> = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
}
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-50 bg-fg/40" />
      <DialogContent
        :class="cn(
          'fixed top-1/2 left-1/2 z-50 flex max-h-[calc(100vh-2rem)] w-[calc(100vw-2rem)] -translate-x-1/2 -translate-y-1/2 flex-col rounded-card border border-border bg-surface text-fg shadow-raised focus:outline-none',
          sizes[props.size],
        )"
      >
        <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
          <div class="flex flex-col gap-1">
            <DialogTitle class="text-base font-semibold">{{ props.title }}</DialogTitle>
            <DialogDescription v-if="props.description" class="text-sm text-fg-muted">
              {{ props.description }}
            </DialogDescription>
          </div>
          <DialogClose
            class="-m-1.5 rounded-control p-1.5 text-fg-muted transition-colors hover:bg-surface-subtle hover:text-fg"
            :aria-label="t('common.close')"
          >
            <X class="size-5" aria-hidden="true" />
          </DialogClose>
        </header>

        <div class="overflow-y-auto px-5 py-4">
          <slot />
        </div>

        <footer v-if="$slots.footer" class="flex justify-end gap-2 border-t border-border px-5 py-3">
          <slot name="footer" />
        </footer>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
