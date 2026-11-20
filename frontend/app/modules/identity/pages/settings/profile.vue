<script setup lang="ts">
/**
 * Your own account.
 *
 * No permission guard: this is the caller's own record, and requiring a grant to
 * read yourself would lock people out of their own settings. The server takes
 * the same position - `GET /api/profile` is authenticated but ungated.
 */
const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const { t, te, locale, setLocale } = useI18n()

// What the tenant named the role, else the built-in role in the reader's
// language (the Role module's catalogue), else the key itself.
function roleLabel(membership: { role: string, roleName: string | null }): string {
  const key = `role.key.${membership.role}`
  return membership.roleName ?? (te(key) ? t(key) : membership.role)
}

const statusTones = { active: 'ok', invited: 'info', suspended: 'warn' } as const

// A status the catalogue does not know yet is shown as its key, not hidden.
function statusLabel(status: string): string {
  const key = `identity.membershipStatus.${status}`
  return te(key) ? t(key) : status
}
const ui = useUiStore()

const form = reactive({
  displayName: auth.user?.displayName ?? '',
  locale: auth.user?.locale ?? locale.value,
})

const busy = ref(false)

const languages = [
  { value: 'en', label: 'English' },
  { value: 'pl', label: 'Polski' },
]

async function save() {
  busy.value = true

  try {
    const user = await api.patch<typeof auth.user>('/api/profile', { ...form })

    // The store holds the session, so the header updates without a reload.
    if (auth.user && user) auth.user = user

    // Applied immediately as well as saved. A language setting that only takes
    // effect on the next login reads as broken.
    await setLocale(form.locale as 'en' | 'pl')
    ui.setLocale(form.locale)

    toast.success(t('identity.saved'))
  } catch (error) {
    toast.error((error as Error).message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <SettingsTabs />

    <UiCard :title="t('identity.profile')">
      <form class="flex max-w-md flex-col gap-4" data-testid="profile-form" @submit.prevent="save">
        <UiField v-slot="field" :label="t('identity.email')" :hint="t('identity.emailHint')">
          <!-- Read-only: changing an address is an account-recovery vector, so
               it needs its own verified flow rather than a field here. -->
          <UiInput :id="field.id" :model-value="auth.user?.email ?? ''" disabled :described-by="field.describedBy" />
        </UiField>

        <UiField v-slot="field" :label="t('identity.displayName')">
          <UiInput
            :id="field.id"
            v-model="form.displayName"
            autocomplete="name"
            data-testid="profile-display-name"
            :described-by="field.describedBy"
          />
        </UiField>

        <UiField v-slot="field" :label="t('identity.language')">
          <UiSelect :id="field.id" v-model="form.locale" :options="languages" :described-by="field.describedBy" />
        </UiField>

        <div>
          <UiButton type="submit" variant="primary" :loading="busy" data-testid="profile-save">
            {{ t('common.save') }}
          </UiButton>
        </div>
      </form>
    </UiCard>

    <UiCard :title="t('identity.memberships')" :description="t('identity.membershipsHint')">
      <UiTable
        :columns="[
          { key: 'tenantName', label: t('identity.workspace') },
          { key: 'role', label: t('identity.role') },
          { key: 'status', label: t('identity.status') },
        ]"
        :rows="auth.memberships"
      >
        <template #cell-role="{ row }">
          <span data-testid="membership-role">{{ roleLabel(row) }}</span>
        </template>

        <template #cell-status="{ row }">
          <UiStatus :tone="statusTones[row.status as keyof typeof statusTones] ?? 'neutral'">
            {{ statusLabel(String(row.status)) }}
          </UiStatus>
        </template>
      </UiTable>
    </UiCard>
  </div>
</template>
