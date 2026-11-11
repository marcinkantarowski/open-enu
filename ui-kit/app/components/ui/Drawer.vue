<script setup lang="ts">
import { X } from '@lucide/vue'
import {
  DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle,
} from 'reka-ui'

/**
 * A panel from the right edge: a record's details, a filter set, a form that
 * belongs to the row it was opened from. The list stays visible behind it, which
 * is what a dialog's centred box takes away.
 */
const open = defineModel<boolean>('open', { default: false })

const props = withDefaults(defineProps<{
  title: string
  description?: string
  width?: 'md' | 'lg' | 'xl'
}>(), {
  description: undefined,
  width: 'md',
})

const { t } = useI18n()

const widths: Record<string, string> = {
  md: 'w-[min(25rem,100vw)]',
  lg: 'w-[min(35rem,100vw)]',
  xl: 'w-[min(47.5rem,100vw)]',
}
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-50 bg-fg/30" />
      <DialogContent
        :class="cn(
          'fixed inset-y-0 right-0 z-50 flex flex-col border-l border-border bg-surface text-fg shadow-raised focus:outline-none',
          widths[props.width],
        )"
      >
        <header class="flex h-14 shrink-0 items-center justify-between gap-4 border-b border-border px-5">
          <div class="flex min-w-0 flex-col">
            <DialogTitle class="truncate text-base font-semibold">{{ props.title }}</DialogTitle>
            <DialogDescription v-if="props.description" class="truncate text-xs text-fg-muted">
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

        <div class="flex-1 overflow-y-auto px-5 py-4">
          <slot />
        </div>

        <footer v-if="$slots.footer" class="flex justify-end gap-2 border-t border-border px-5 py-3">
          <slot name="footer" />
        </footer>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
