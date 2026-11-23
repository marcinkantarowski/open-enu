<script setup lang="ts">
/**
 * Platform settings, and the per-tenant overrides on top of them.
 *
 * This is the kill switch: an operator can turn a feature off for one tenant
 * during an incident, and the tenant cannot turn it back on - settings that are
 * not `tenantEditable` are refused on the tenant-facing endpoint.
 */
interface Setting {
  id: string
  identifier: string
  name: string
  description: string | null
  type: string
  defaultValue: unknown
  tenantEditable: boolean
  category: string | null
}

const api = useApi()
const route = useRoute()
const toast = useToast()
const { t } = useI18n()

const { data, pending } = await useAsyncData(
  'platform-settings',
  () => api.get<{ items: Setting[] }>('/api/manager/settings'),
)

// An override needs a tenant to apply to, so the operator names one first.
// Deliberately a plain field rather than a picker: a picker over every tenant
// on the platform is not a usable control. The tenant's page links here with
// `?tenant=`, so the usual way in arrives filled - and named, so an override
// is never saved against a UUID nobody recognised.
const tenantId = ref(String(route.query.tenant ?? ''))
const tenantName = useTenantName(tenantId)
const saving = ref<string | null>(null)

async function override(setting: Setting, value: unknown) {
  if (!tenantId.value) {
    toast.error(t('manager.pickTenantFirst'))
    return
  }

  saving.value = setting.identifier

  try {
    await api.put(`/api/manager/tenants/${tenantId.value}/settings/${setting.identifier}`, { value })
    toast.success(t('manager.overrideSaved', { tenant: tenantName.value ?? tenantId.value }))
  } catch (error) {
    toast.error((error as Error).message)
  } finally {
    saving.value = null
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <div>
      <h1 class="text-lg font-semibold text-fg">{{ t('manager.flags') }}</h1>
      <p class="text-sm text-fg-muted">{{ t('manager.flagsHint') }}</p>
    </div>

    <UiCard :title="t('manager.applyTo')" :description="t('manager.applyToHint')">
      <UiField v-slot="field" :label="t('manager.tenant')" :hint="tenantName ?? undefined">
        <UiInput
          :id="field.id"
          v-model="tenantId"
          placeholder="UUID"
          data-testid="flag-tenant"
          :described-by="field.describedBy"
        />
      </UiField>
    </UiCard>

    <UiCard>
      <UiTable
        :columns="[
          { key: 'name', label: t('manager.setting') },
          { key: 'identifier', label: t('manager.identifier') },
          { key: 'defaultValue', label: t('manager.default') },
          { key: 'tenantEditable', label: t('manager.tenantEditable') },
          { key: 'actions', label: t('manager.override') },
        ]"
        :rows="data?.items ?? []"
        :loading="pending"
        :empty-title="t('manager.noSettings')"
      >
        <template #cell-identifier="{ row }">
          <span class="font-mono text-xs">{{ row.identifier }}</span>
        </template>

        <template #cell-defaultValue="{ row }">
          <span v-if="typeof row.defaultValue === 'boolean'">{{ row.defaultValue ? t('manager.valueOn') : t('manager.valueOff') }}</span>
          <span v-else class="font-mono text-xs">{{ JSON.stringify(row.defaultValue) }}</span>
        </template>

        <template #cell-tenantEditable="{ row }">
          <UiStatus :tone="row.tenantEditable ? 'neutral' : 'warn'">
            {{ row.tenantEditable ? t('common.yes') : t('manager.operatorOnly') }}
          </UiStatus>
        </template>

        <template #cell-actions="{ row }">
          <div v-if="row.type === 'bool'" class="flex gap-1">
            <UiButton
              size="sm"
              :loading="saving === row.identifier"
              :data-testid="`flag-on-${row.identifier}`"
              @click="override(row, true)"
            >
              {{ t('manager.turnOn') }}
            </UiButton>
            <UiButton
              size="sm"
              variant="danger"
              :loading="saving === row.identifier"
              :data-testid="`flag-off-${row.identifier}`"
              @click="override(row, false)"
            >
              {{ t('manager.turnOff') }}
            </UiButton>
          </div>
          <span v-else class="text-xs text-fg-muted">{{ t('manager.notToggleable') }}</span>
        </template>
      </UiTable>
    </UiCard>
  </div>
</template>
