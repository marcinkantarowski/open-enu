<script setup lang="ts">
definePageMeta({ layout: 'auth', public: true })

const api = useApi()
const route = useRoute()
const { t } = useI18n()

const state = ref<'working' | 'done' | 'failed'>('working')
const message = ref('')

onMounted(async () => {
  const token = typeof route.query.token === 'string' ? route.query.token : ''

  if (!token) {
    state.value = 'failed'
    message.value = t('app.verify.missingToken')
    return
  }

  try {
    await api.post('/api/auth/verify', { token })
    state.value = 'done'
  } catch (e) {
    state.value = 'failed'
    // One message for expired, unknown and already-used: distinguishing them
    // would tell someone which links were real.
    message.value = (e as Error).message
  }
})
</script>

<template>
  <div class="flex flex-col items-center gap-3 text-center">
    <UiSpinner v-if="state === 'working'" class="size-6 text-brand-600" />

    <template v-else-if="state === 'done'">
      <p class="text-sm font-semibold text-fg">{{ t('app.verify.done') }}</p>
      <NuxtLink to="/login" class="text-xs text-brand-700 hover:underline">{{ t('auth.signIn') }}</NuxtLink>
    </template>

    <template v-else>
      <UiAlert tone="danger">{{ message }}</UiAlert>
      <NuxtLink to="/login" class="text-xs text-fg-muted hover:underline">{{ t('auth.signIn') }}</NuxtLink>
    </template>
  </div>
</template>
