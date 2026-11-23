<script setup lang="ts">
/**
 * The audit trail across every tenant.
 *
 * The reason this exists separately from the tenant-facing one: an operator
 * investigating an incident needs to look at a tenant they are not a member of,
 * and needs to see impersonated actions attributed to the operator who performed
 * them rather than to the customer.
 */
interface AuditEntry {
  id: string
  action: string
  tenantId: string | null
  actorId: string | null
  onBehalfOfId: string | null
  requestId: string | null
  succeeded: boolean
  recordedAt: string
}

const api = useApi()
const route = useRoute()
const { t } = useI18n()
const dateTime = useDateTime()

// Filled when arriving from a tenant's page (`?tenant=`), and named either way.
const tenantId = ref(String(route.query.tenant ?? ''))
const tenantName = useTenantName(tenantId)

const { data, pending, refresh } = await useAsyncData(
  'manager-audit',
  () => api.get<{ items: AuditEntry[] }>('/api/manager/audit', {
    query: { tenantId: tenantId.value || undefined, limit: 200 },
  }),
)
</script>

<template>
  <div class="flex flex-col gap-6">
    <div class="flex items-end justify-between gap-4">
      <div>
        <h1 class="text-lg font-semibold text-fg">{{ t('manager.audit') }}</h1>
        <p class="text-sm text-fg-muted">{{ t('manager.auditHint') }}</p>
      </div>

      <form class="flex items-end gap-2" @submit.prevent="refresh()">
        <UiField v-slot="field" :label="t('manager.filterTenant')" :hint="tenantName ?? undefined">
          <UiInput :id="field.id" v-model="tenantId" placeholder="UUID" :described-by="field.describedBy" />
        </UiField>
        <UiButton type="submit">{{ t('common.search') }}</UiButton>
      </form>
    </div>

    <UiCard>
      <UiTable
        :columns="[
          { key: 'recordedAt', label: t('manager.when') },
          { key: 'action', label: t('manager.action') },
          { key: 'tenantId', label: t('manager.tenant') },
          { key: 'actorId', label: t('manager.actor') },
          { key: 'succeeded', label: t('manager.outcome') },
          { key: 'requestId', label: t('manager.request') },
        ]"
        :rows="data?.items ?? []"
        :loading="pending"
        :empty-title="t('manager.noAudit')"
      >
        <template #cell-recordedAt="{ row }">{{ dateTime(row.recordedAt) }}</template>

        <template #cell-tenantId="{ row }">
          <NuxtLink v-if="row.tenantId" :to="`/tenants/${row.tenantId}`" class="font-mono text-xs text-accent-700 hover:underline" :title="row.tenantId">
            {{ row.tenantId.slice(0, 8) }}
          </NuxtLink>
          <span v-else>-</span>
        </template>

        <template #cell-action="{ row }">
          <span class="font-mono text-xs">{{ row.action }}</span>
        </template>

        <template #cell-actorId="{ row }">
          <span class="font-mono text-xs">{{ row.actorId ?? '-' }}</span>
          <!-- The distinction the tenant-facing view cannot make: which of these
               changes an operator made while wearing someone else's face. -->
          <UiStatus v-if="row.onBehalfOfId" tone="warn" class="ml-1">{{ t('manager.impersonated') }}</UiStatus>
        </template>

        <template #cell-succeeded="{ row }">
          <UiStatus :tone="row.succeeded ? 'ok' : 'crit'">
            {{ row.succeeded ? t('manager.outcomeOk') : t('manager.outcomeFailed') }}
          </UiStatus>
        </template>

        <template #cell-requestId="{ row }">
          <span class="font-mono text-[11px] text-fg-muted">{{ row.requestId ?? '-' }}</span>
        </template>
      </UiTable>
    </UiCard>
  </div>
</template>
