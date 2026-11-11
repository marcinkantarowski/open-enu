<script setup lang="ts">
import { TooltipContent, TooltipPortal, TooltipProvider, TooltipRoot, TooltipTrigger } from 'reka-ui'

/**
 * A short explanation of a control, on hover and on keyboard focus. Never the
 * only place something is said: a touch screen has no hover, so the tooltip
 * explains and the control's own label still names it.
 */
defineProps<{ content: string, side?: 'top' | 'right' | 'bottom' | 'left' }>()
</script>

<template>
  <TooltipProvider :delay-duration="300">
    <TooltipRoot>
      <TooltipTrigger as-child>
        <slot />
      </TooltipTrigger>
      <TooltipPortal>
        <TooltipContent
          :side="side ?? 'top'"
          :side-offset="6"
          class="z-50 max-w-xs rounded-sm bg-fg px-2.5 py-1.5 text-xs text-surface shadow-raised"
        >
          {{ content }}
        </TooltipContent>
      </TooltipPortal>
    </TooltipRoot>
  </TooltipProvider>
</template>
