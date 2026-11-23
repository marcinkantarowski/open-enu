<script setup lang="ts">
// The menu's "Settings" lands here for everyone. Without settings.view this
// screen is not theirs, but other tabs are - the profile at least - so they
// are sent to the first one rather than shown a 403.
definePageMeta({
  middleware: [() => {
    if (!useAuthStore().can('settings.view')) return navigateTo(settingsHome(), { replace: true })
  }],
})

/**
 * The workspace's own settings.
 *
 * Only entries the platform marks `tenantEditable` can be changed here. The rest
 * are operator kill switches, shown deliberately rather than hidden: "why can I
 * not turn this on?" is answerable, while a missing row is not.
 */
interface Setting {
  identifier: string
  name: string
  description: string | null
  type: string
  tenantEditable: boolean
  effectiveValue: unknown
  overridden: boolean
  category: string | null
}

const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()

const { data, pending, refresh } = await useAsyncData(
  'tenant-settings',
  () => api.get<{ items: Setting[] }>('/api/settings'),
)

// The reference module's switches follow DEMO_CONTENT like its menu entry: a
// product that does not offer the module does not offer its settings either.
const DEMO_CATEGORIES = ['example']
const { demoContent } = useRuntimeConfig().public
const items = computed(() => (data.value?.items ?? []).filter(s => demoContent || !DEMO_CATEGORIES.includes(s.category ?? '')))

const saving = ref<string | null>(null)

async function save(setting: Setting, value: unknown) {
  saving.value = setting.identifier

  try {
    await api.put(`/api/settings/${setting.identifier}`, { value })
    toast.success(t('settings.saved'))
    await refresh()
  } catch (error) {
    toast.error((error as Error).message)
  } finally {
    saving.value = null
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <SettingsTabs />

    <UiCard :title="t('settings.workspace')" :description="t('settings.hint')">
      <UiEmptyState v-if="!pending && items.length === 0" />

      <ul v-else class="flex flex-col divide-y divide-border">
        <li
          v-for="setting in items"
          :key="setting.identifier"
          class="flex items-center justify-between gap-4 py-3"
        >
          <div class="min-w-0">
            <p class="text-sm text-fg">{{ setting.name }}</p>
            <p v-if="setting.description" class="text-xs text-fg-muted">{{ setting.description }}</p>
            <p class="mt-0.5 font-mono text-[11px] text-fg-muted">{{ setting.identifier }}</p>
          </div>

          <div class="flex shrink-0 items-center gap-2">
            <UiTag v-if="setting.overridden" tone="accent">{{ t('settings.overridden') }}</UiTag>

            <UiTag v-if="!setting.tenantEditable">
              {{ t('settings.platformManaged') }}
            </UiTag>

            <input
              v-else-if="setting.type === 'bool'"
              type="checkbox"
              class="size-4"
              :checked="setting.effectiveValue === true"
              :disabled="saving === setting.identifier || !auth.can('settings.manage')"
              :aria-label="setting.name"
              @change="save(setting, ($event.target as HTMLInputElement).checked)"
            >

            <span v-else class="font-mono text-xs text-fg">{{ setting.effectiveValue }}</span>
          </div>
        </li>
      </ul>
    </UiCard>
  </div>
</template>
