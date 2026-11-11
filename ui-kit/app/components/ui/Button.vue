<script setup lang="ts">
import type { Component } from 'vue'

/**
 * The one button.
 *
 * Variants rather than free-form classes, so "what does a destructive action look
 * like?" has a single answer that can be changed once. With `to` it renders a link
 * that looks like a button - navigation stays a link, for the browser and for a
 * screen reader.
 *
 * Icon-only: pass `icon` and no text, and give it an `aria-label`. A button with no
 * name is announced as "button", which tells nobody anything.
 */
const props = withDefaults(defineProps<{
  variant?: 'primary' | 'secondary' | 'tertiary' | 'ghost' | 'danger'
  size?: 'sm' | 'md' | 'lg'
  type?: 'button' | 'submit'
  to?: string
  icon?: Component
  iconRight?: Component
  disabled?: boolean
  loading?: boolean
}>(), {
  variant: 'secondary',
  size: 'md',
  type: 'button',
  to: undefined,
  icon: undefined,
  iconRight: undefined,
  disabled: false,
  loading: false,
})

const slots = useSlots()
// Resolved rather than imported: `#components` is Nuxt's virtual module, which
// tools outside the build (knip, the IDE) cannot follow.
const NuxtLink = resolveComponent('NuxtLink')

const variants: Record<string, string> = {
  primary: 'border-transparent bg-accent text-accent-fg hover:bg-accent-hover active:bg-accent-active',
  secondary: 'border-accent bg-surface text-accent hover:bg-selected active:bg-accent-100',
  tertiary: 'border-transparent bg-transparent text-accent hover:bg-selected',
  ghost: 'border-transparent bg-transparent text-fg hover:bg-surface-subtle',
  danger: 'border-transparent bg-crit text-white hover:brightness-90 active:brightness-80',
}

const sizes: Record<string, string> = {
  sm: 'h-control-sm gap-1.5 px-3 text-xs',
  md: 'h-control-md gap-2 px-4 text-sm',
  // The one action a whole screen exists for - a sign-in, a submit.
  lg: 'h-control-lg gap-2 px-5 text-base',
}

const square: Record<string, string> = {
  sm: 'w-control-sm px-0',
  md: 'w-control-md px-0',
  lg: 'w-control-lg px-0',
}

const iconOnly = computed(() => Boolean(props.icon) && !slots.default)
const iconSize = computed(() => (props.size === 'sm' ? 'size-4' : 'size-5'))
const classes = computed(() => cn(
  'inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-control border font-semibold transition-colors duration-150 ease-standard disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
  variants[props.variant],
  sizes[props.size],
  iconOnly.value && square[props.size],
))
</script>

<template>
  <component :is="NuxtLink" v-if="props.to" :to="props.to" :class="classes" :aria-disabled="props.disabled || undefined">
    <component :is="props.icon" v-if="props.icon" :class="iconSize" aria-hidden="true" />
    <slot />
    <component :is="props.iconRight" v-if="props.iconRight" :class="iconSize" aria-hidden="true" />
  </component>
  <button
    v-else
    :type="props.type"
    :disabled="props.disabled || props.loading"
    :aria-busy="props.loading || undefined"
    :class="classes"
  >
    <UiSpinner v-if="props.loading" :class="iconSize" />
    <component :is="props.icon" v-else-if="props.icon" :class="iconSize" aria-hidden="true" />
    <slot />
    <component :is="props.iconRight" v-if="props.iconRight" :class="iconSize" aria-hidden="true" />
  </button>
</template>
