<script setup lang="ts">
/**
 * The product's mark: its initial on the brand colour, and optionally its name.
 *
 * A letter rather than an image so that a project renamed by `make init` has a
 * mark that is already its own. A project with a real logo replaces this one
 * component from its `theme/` layer (ADR-0024); every shell and sign-in screen
 * renders it.
 *
 * `tone`: `brand` on a light surface, `inverse` on a dark one, `nav` on the
 * navigation column - which is light or dark as the theme decides.
 */
const props = withDefaults(defineProps<{ name: string, tone?: 'brand' | 'inverse' | 'nav', showName?: boolean }>(), {
  tone: 'brand',
  showName: true,
})

const initial = computed(() => props.name.trim().charAt(0).toUpperCase())
</script>

<template>
  <span class="inline-flex items-center gap-2.5">
    <span
      class="inline-flex size-8 shrink-0 items-center justify-center rounded-control text-sm font-bold"
      :class="props.tone === 'inverse' ? 'bg-white/15 text-white ring-1 ring-white/25' : 'bg-accent text-accent-fg'"
      aria-hidden="true"
    >{{ initial }}</span>
    <span
      v-if="props.showName"
      class="text-sm font-semibold tracking-tight"
      :class="{ 'text-white': props.tone === 'inverse', 'text-nav-title': props.tone === 'nav', 'text-fg': props.tone === 'brand' }"
    >{{ props.name }}</span>
  </span>
</template>
