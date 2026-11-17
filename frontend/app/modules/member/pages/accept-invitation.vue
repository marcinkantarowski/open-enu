<script setup lang="ts">
// Public: the link is the credential. Not `guest`-only - someone already signed
// in to another tenant accepts an invitation to this one exactly the same way.
definePageMeta({ layout: 'auth', public: true })

interface Invitation { tenantName: string, email: string, needsPassword: boolean }

const api = useApi()
const route = useRoute()
const { t } = useI18n()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))

const { data: invitation } = await useAsyncData('invitation', () =>
  token.value ? api.get<Invitation>(`/api/auth/invitation?token=${encodeURIComponent(token.value)}`).catch(() => null) : Promise.resolve(null),
)

const name = ref('')
const password = ref('')
const error = ref<string | null>(null)
const done = ref(false)
const busy = ref(false)

async function accept() {
  busy.value = true
  error.value = null

  try {
    await api.post('/api/auth/invitation', {
      token: token.value,
      password: invitation.value?.needsPassword ? password.value : null,
      displayName: name.value || null,
    })
    done.value = true
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <template v-if="!invitation">
      <AuthHeading :title="t('member.title')" />
      <UiAlert tone="warn" data-testid="invitation-invalid">{{ t('member.accept.invalid') }}</UiAlert>
    </template>

    <template v-else-if="done">
      <AuthHeading :title="t('member.accept.title', { tenant: invitation.tenantName })" />
      <UiAlert tone="ok" data-testid="invitation-done">{{ t('member.accept.done') }}</UiAlert>
      <UiButton class="mt-6 w-full" variant="primary" size="lg" :to="`/login?email=${encodeURIComponent(invitation.email)}`">
        {{ t('app.login.title') }}
      </UiButton>
    </template>

    <template v-else>
      <AuthHeading
        :title="t('member.accept.title', { tenant: invitation.tenantName })"
        :subtitle="t('member.accept.subtitle', { email: invitation.email })"
      />

      <form class="flex flex-col gap-5" @submit.prevent="accept">
        <template v-if="invitation.needsPassword">
          <UiField v-slot="field" :label="t('member.accept.name')">
            <UiInput :id="field.id" v-model="name" autocomplete="name" data-testid="invitation-name" />
          </UiField>
          <UiField v-slot="field" :label="t('member.accept.password')" :hint="t('member.accept.passwordHint')" required>
            <UiInput
              :id="field.id"
              v-model="password"
              type="password"
              autocomplete="new-password"
              :described-by="field.describedBy"
              data-testid="invitation-password"
            />
          </UiField>
        </template>

        <UiAlert v-if="error" tone="crit">{{ error }}</UiAlert>

        <UiButton type="submit" variant="primary" size="lg" :loading="busy" data-testid="invitation-accept">
          {{ t('member.accept.submit') }}
        </UiButton>
      </form>
    </template>
  </div>
</template>
