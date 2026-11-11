<script setup lang="ts">
import type { Component } from 'vue'
import { Inbox } from '@lucide/vue'

/**
 * What an empty list says instead of nothing: why it is empty, and the one thing
 * to do about it (the slot - usually a button).
 */
defineProps<{ title?: string, description?: string, icon?: Component }>()
const { t } = useI18n()
</script>

<template>
  <div class="flex flex-col items-center gap-2 px-6 py-12 text-center">
    <component :is="icon ?? Inbox" class="mb-1 size-8 text-fg-muted" aria-hidden="true" />
    <p class="text-sm font-semibold text-fg">{{ title ?? t('empty.title') }}</p>
    <p class="max-w-sm text-sm text-fg-muted">{{ description ?? t('empty.description') }}</p>
    <div v-if="$slots.default" class="mt-2">
      <slot />
    </div>
  </div>
</template>
