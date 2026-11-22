<script setup lang="ts">
definePageMeta({ middleware: 'permission', permission: 'audit.view' })

/**
 * What happened in this workspace.
 *
 * Every command the bus dispatched, with before/after snapshots. Scoped to the
 * caller's tenant by the controller rather than by the query filter - the entity
 * is deliberately unscoped so an operator can investigate across tenants, and the
 * restriction belongs where the authority is decided.
 */
interface AuditEntry {
  id: string
  action: string
  actorId: string | null
  onBehalfOfId: string | null
  subjectId: string | null
  requestId: string | null
  succeeded: boolean
  failureReason: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  recordedAt: string
}

const api = useApi()
const { t } = useI18n()

const { data, pending } = await useAsyncData('audit', () => api.get<{ items: AuditEntry[] }>('/api/audit'))

// Shown in a drawer beside the list: under a table of fifty rows the
// snapshots would land below the fold, far from the row that asked for them.
const selected = ref<AuditEntry | null>(null)
const detailsOpen = computed({
  get: () => selected.value !== null,
  set: (open: boolean) => { if (!open) selected.value = null },
})
</script>

<template>
  <div class="flex flex-col gap-6">
    <div>
      <h1 class="text-lg font-semibold text-fg">{{ t('audit.title') }}</h1>
      <p class="text-sm text-fg-muted">{{ t('audit.subtitle') }}</p>
    </div>

    <UiCard>
      <UiTable
        :columns="[
          { key: 'recordedAt', label: t('audit.when') },
          { key: 'action', label: t('audit.action') },
          { key: 'actorId', label: t('audit.actor') },
          { key: 'succeeded', label: t('audit.outcome') },
          { key: 'details', label: '' },
        ]"
        :rows="data?.items ?? []"
        :loading="pending"
        :empty-title="t('audit.empty')"
      >
        <template #cell-action="{ row }">
          <span class="font-mono text-xs">{{ row.action }}</span>
        </template>

        <template #cell-actorId="{ row }">
          <!-- Both identities when one is acting for another: an impersonated
               change must never read as the customer's own (ADR-0008). -->
          <span class="font-mono text-xs">{{ row.actorId ?? '-' }}</span>
          <span v-if="row.onBehalfOfId" class="ml-1 text-xs text-warn">
            {{ t('audit.onBehalfOf') }}
          </span>
        </template>

        <template #cell-succeeded="{ row }">
          <UiStatus :tone="row.succeeded ? 'ok' : 'crit'">
            {{ row.succeeded ? t('audit.ok') : t('audit.failed') }}
          </UiStatus>
        </template>

        <template #cell-details="{ row }">
          <UiButton variant="ghost" size="sm" data-testid="audit-details" @click="selected = row">
            {{ t('audit.show') }}
          </UiButton>
        </template>
      </UiTable>
    </UiCard>

    <UiDrawer v-model:open="detailsOpen" :title="selected?.action ?? ''" :description="selected?.recordedAt" width="lg">
      <div v-if="selected" class="flex flex-col gap-4" data-testid="audit-snapshot">
        <p v-if="selected.failureReason" class="text-sm text-crit">{{ selected.failureReason }}</p>
        <div v-for="side in ['before', 'after'] as const" :key="side">
          <p class="mb-1 text-xs font-medium uppercase tracking-wide text-fg-muted">{{ t(`audit.${side}`) }}</p>
          <pre class="overflow-x-auto rounded-lg bg-surface-subtle p-3 font-mono text-[11px] text-fg">{{ JSON.stringify(selected[side], null, 2) }}</pre>
        </div>
      </div>
    </UiDrawer>
  </div>
</template>
