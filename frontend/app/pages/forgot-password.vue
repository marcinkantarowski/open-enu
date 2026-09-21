<script setup lang="ts">
definePageMeta({ layout: 'auth', public: true, middleware: 'guest' })

const api = useApi()
const { t } = useI18n()

const email = ref('')
const busy = ref(false)
const sent = ref(false)

async function submit() {
  busy.value = true

  try {
    await api.post('/api/auth/forgot-password', { email: email.value })
  } finally {
    // Shown whatever happened, including on failure. A form that behaves
    // differently for a known address turns password reset into a way to test
    // whether someone has an account here.
    sent.value = true
    busy.value = false
  }
}
</script>

<template>
  <p v-if="sent" class="text-sm text-fg-muted">{{ t('app.forgot.sent') }}</p>

  <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
    <UiField v-slot="field" :label="t('auth.email')" required>
      <UiInput :id="field.id" v-model="email" type="email" autocomplete="username" :described-by="field.describedBy" />
    </UiField>

    <UiButton type="submit" variant="primary" :loading="busy">{{ t('app.forgot.submit') }}</UiButton>

    <NuxtLink to="/login" class="text-center text-xs text-fg-muted hover:underline">
      {{ t('auth.signIn') }}
    </NuxtLink>
  </form>
</template>
