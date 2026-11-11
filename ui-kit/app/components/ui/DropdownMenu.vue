<script setup lang="ts">
import type { Component } from 'vue'
import {
  DropdownMenuContent, DropdownMenuItem, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuSeparator, DropdownMenuTrigger,
} from 'reka-ui'

/**
 * A list of actions behind one button - a row's "more", the account menu. The
 * trigger is the default slot and must be a button with a name.
 *
 * A destructive item is marked `danger`, and it should still ask for confirmation:
 * a menu is one stray click away from its neighbour.
 */
export interface MenuItem {
  label: string
  icon?: Component
  danger?: boolean
  disabled?: boolean
  separatorBefore?: boolean
  select: () => void
}

defineProps<{ items: MenuItem[], align?: 'start' | 'end' }>()
</script>

<template>
  <DropdownMenuRoot>
    <DropdownMenuTrigger as-child>
      <slot />
    </DropdownMenuTrigger>
    <DropdownMenuPortal>
      <DropdownMenuContent
        :align="align ?? 'end'"
        :side-offset="4"
        class="z-50 min-w-48 rounded-control border border-border bg-surface p-1 shadow-raised"
      >
        <template v-for="item in items" :key="item.label">
          <DropdownMenuSeparator v-if="item.separatorBefore" class="my-1 h-px bg-border" />
          <DropdownMenuItem
            :disabled="item.disabled"
            :class="cn(
              'flex h-control-sm cursor-default select-none items-center gap-2 rounded-sm px-2.5 text-sm text-fg outline-none data-disabled:opacity-50 data-highlighted:bg-selected',
              item.danger && 'text-crit data-highlighted:bg-crit-soft',
            )"
            @select="item.select()"
          >
            <component :is="item.icon" v-if="item.icon" class="size-4" aria-hidden="true" />
            {{ item.label }}
          </DropdownMenuItem>
        </template>
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>
