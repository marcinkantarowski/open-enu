<script setup lang="ts">
import type { PagesEnCollectionItem } from '@nuxt/content'

defineProps<{ hero: NonNullable<PagesEnCollectionItem['hero']> }>()
</script>

<template>
  <section class="bg-gradient-to-b from-accent-50 to-surface">
    <div class="mx-auto max-w-4xl px-6 py-20 text-center sm:py-28">
      <p v-if="hero.eyebrow" class="text-sm font-semibold uppercase tracking-wider text-accent-600">{{ hero.eyebrow }}</p>
      <h1 class="mt-3 text-4xl font-bold tracking-tight text-fg sm:text-5xl">{{ hero.title }}</h1>
      <p class="mx-auto mt-6 max-w-2xl text-lg text-fg-muted">{{ hero.subtitle }}</p>

      <div class="mt-10 flex flex-wrap items-center justify-center gap-3">
        <SiteLink :to="hero.primary.to" class="rounded-lg bg-accent-600 px-5 py-3 text-sm font-semibold text-white hover:bg-accent-700">
          {{ hero.primary.label }}
        </SiteLink>
        <SiteLink
          v-if="hero.secondary"
          :to="hero.secondary.to"
          class="rounded-lg border border-border bg-surface px-5 py-3 text-sm font-semibold text-fg hover:bg-surface-subtle"
        >
          {{ hero.secondary.label }}
        </SiteLink>
      </div>

      <p v-if="hero.note" class="mt-4 text-sm text-fg-muted">{{ hero.note }}</p>
    </div>
  </section>
</template>
