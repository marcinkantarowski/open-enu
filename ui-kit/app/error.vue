<script setup lang="ts">
/**
 * Every app's error page. Nuxt's own is in English and shows a developer's
 * view of the failure; a user gets their own language, what happened in one
 * sentence, and a way back.
 */
// Only the status is read: the message and stack are a developer's, not a user's.
const props = defineProps<{ error: { statusCode: number } }>()
const { t } = useI18n()

const kind = computed(() => (props.error.statusCode === 404 ? 'notFound' : 'failed'))
useHead({ title: () => t(`errorPage.${kind.value}.title`) })

const home = () => clearError({ redirect: '/' })
</script>

<template>
  <main class="flex min-h-dvh flex-col items-center justify-center gap-3 bg-surface-subtle p-6 text-center text-fg" data-testid="error-page">
    <p class="text-5xl font-semibold tabular-nums text-fg-muted">{{ props.error.statusCode }}</p>
    <h1 class="text-xl font-semibold">{{ t(`errorPage.${kind}.title`) }}</h1>
    <p class="max-w-md text-sm text-fg-muted">{{ t(`errorPage.${kind}.hint`) }}</p>
    <UiButton variant="primary" @click="home">{{ t('errorPage.home') }}</UiButton>
  </main>
</template>
