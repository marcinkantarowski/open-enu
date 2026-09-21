<script setup lang="ts">
definePageMeta({ layout: 'auth', public: true, middleware: 'guest' })

const api = useApi()
const { t } = useI18n()

const form = reactive({ tenantName: '', email: '', password: '', displayName: '' })
const error = ref<string | null>(null)
const busy = ref(false)
const sent = ref(false)

async function submit() {
  busy.value = true
  error.value = null

  try {
    await api.post('/api/auth/register', { ...form, locale: 'en' })
    // 202, not a session: the account is unusable until the address is proved,
    // so there is deliberately nothing to sign into yet.
    sent.value = true
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="sent" class="flex flex-col gap-3">
    <p class="text-sm font-semibold text-fg">{{ t('app.register.checkEmailTitle') }}</p>
    <p class="text-xs text-fg-muted">{{ t('app.register.checkEmailBody', { email: form.email }) }}</p>
    <NuxtLink to="/login" class="text-xs text-brand-700 hover:underline">{{ t('auth.signIn') }}</NuxtLink>
  </div>

  <form v-else class="flex flex-col gap-4" @submit.prevent="submit">
    <UiField v-slot="field" :label="t('app.register.workspace')" required>
      <UiInput :id="field.id" v-model="form.tenantName" :described-by="field.describedBy" />
    </UiField>

    <UiField v-slot="field" :label="t('app.register.yourName')">
      <UiInput :id="field.id" v-model="form.displayName" autocomplete="name" :described-by="field.describedBy" />
    </UiField>

    <UiField v-slot="field" :label="t('auth.email')" required>
      <UiInput :id="field.id" v-model="form.email" type="email" autocomplete="username" :described-by="field.describedBy" />
    </UiField>

    <UiField
      v-slot="field"
      :label="t('auth.password')"
      :hint="t('app.register.passwordHint')"
      required
    >
      <UiInput
        :id="field.id"
        v-model="form.password"
        type="password"
        autocomplete="new-password"
        :described-by="field.describedBy"
      />
    </UiField>

    <UiAlert v-if="error" tone="danger">{{ error }}</UiAlert>

    <UiButton type="submit" variant="primary" :loading="busy">{{ t('app.register.submit') }}</UiButton>

    <NuxtLink to="/login" class="text-center text-xs text-fg-muted hover:underline">
      {{ t('auth.signIn') }}
    </NuxtLink>
  </form>
</template>
