<script setup lang="ts">
import { Archive, FolderInput, Pencil, Plus } from '@lucide/vue'

definePageMeta({ middleware: 'permission', permission: 'unit.view' })

/**
 * The tenant's tree of units (ADR-0025). Moving or archiving a unit changes
 * who sees which records on their next request, so every change is one
 * deliberate dialog, and the tree is re-read after it rather than patched.
 */
interface Unit {
  id: string
  parentId: string | null
  name: string
  kind: string
  depth: number
  archived: boolean
  version: number
}

const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()

const { data, refresh } = await useAsyncData('units', () => api.get<{ items: Unit[] }>('/api/units'))
const units = computed(() => data.value?.items ?? [])
const canManage = computed(() => auth.can('unit.manage'))

// The picker's value for "no parent". Not '': a Select item may not have an
// empty value, which the Select reserves for "nothing chosen".
const TOP = 'top'

type Mode = 'create' | 'rename' | 'move'
const dialog = reactive({ open: false, mode: 'create' as Mode, unit: null as Unit | null, parentId: TOP, name: '', kind: '' })
const busy = ref(false)

const titles: Record<Mode, string> = { create: 'unit.add', rename: 'unit.rename', move: 'unit.move' }

function open(mode: Mode, unit: Unit | null = null) {
  Object.assign(dialog, {
    open: true,
    mode,
    unit,
    parentId: (mode === 'create' ? unit?.id : unit?.parentId) ?? TOP,
    name: mode === 'rename' ? unit?.name ?? '' : '',
    kind: mode === 'rename' ? unit?.kind ?? '' : '',
  })
}

// Where a unit may go: anywhere but under itself, and never into an archived unit.
const targets = computed(() => {
  const moving = dialog.mode === 'move' ? dialog.unit : null
  const below = new Set<string>(moving ? [moving.id] : [])
  for (const unit of units.value) {
    if (unit.parentId && below.has(unit.parentId)) below.add(unit.id)
  }

  return [
    { value: TOP, label: t('unit.topLevel') },
    ...units.value
      .filter(unit => !unit.archived && !below.has(unit.id))
      .map(unit => ({ value: unit.id, label: `${'- '.repeat(unit.depth)}${unit.name}` })),
  ]
})

async function save() {
  busy.value = true
  const parentId = dialog.parentId === TOP ? null : dialog.parentId

  try {
    if (dialog.mode === 'create') {
      await api.post('/api/units', { name: dialog.name, kind: dialog.kind, parentId })
    } else if (dialog.mode === 'rename' && dialog.unit) {
      await api.patch(`/api/units/${dialog.unit.id}`, { name: dialog.name, kind: dialog.kind }, {
        headers: { 'If-Match': `"${dialog.unit.version}"` },
      })
    } else if (dialog.unit) {
      await api.post(`/api/units/${dialog.unit.id}/move`, { parentId })
    }
    dialog.open = false
    toast.success(t('unit.saved'))
    await refresh()
  } catch (error) {
    toast.error((error as Error).message)
  } finally {
    busy.value = false
  }
}

async function archive(unit: Unit) {
  if (!confirm(t('unit.archiveConfirm', { name: unit.name }))) return

  try {
    await api.post(`/api/units/${unit.id}/archive`)
    toast.success(t('unit.archived'))
    await refresh()
  } catch (error) {
    toast.error((error as Error).message)
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <SettingsTabs />

    <UiCard :title="t('unit.title')" :description="t('unit.hint')">
      <template #header>
        <UiButton v-if="canManage" variant="primary" size="sm" :icon="Plus" data-testid="unit-new" @click="open('create')">
          {{ t('unit.add') }}
        </UiButton>
      </template>

      <UiTable
        :caption="t('unit.title')"
        :columns="[
          { key: 'name', label: t('unit.name') },
          { key: 'kind', label: t('unit.kind') },
          { key: 'archived', label: t('unit.state') },
          { key: 'actions', label: '' },
        ]"
        :rows="units"
        :empty-title="t('unit.empty')"
      >
        <template #cell-name="{ row }">
          <span :style="{ paddingInlineStart: `${row.depth * 1.25}rem` }" data-testid="unit-name">{{ row.name }}</span>
        </template>

        <template #cell-archived="{ row }">
          <UiStatus :tone="row.archived ? 'neutral' : 'ok'">
            {{ row.archived ? t('unit.isArchived') : t('unit.isActive') }}
          </UiStatus>
        </template>

        <template #cell-actions="{ row }">
          <div v-if="canManage && !row.archived" class="flex justify-end gap-1">
            <UiTooltip :content="t('unit.addChild')">
              <UiButton variant="ghost" size="sm" :icon="Plus" :aria-label="t('unit.addChildOf', { name: row.name })" @click="open('create', row)" />
            </UiTooltip>
            <UiTooltip :content="t('unit.rename')">
              <UiButton variant="ghost" size="sm" :icon="Pencil" :aria-label="t('unit.renameNamed', { name: row.name })" @click="open('rename', row)" />
            </UiTooltip>
            <UiTooltip :content="t('unit.move')">
              <UiButton variant="ghost" size="sm" :icon="FolderInput" :aria-label="t('unit.moveNamed', { name: row.name })" @click="open('move', row)" />
            </UiTooltip>
            <UiTooltip :content="t('unit.archive')">
              <UiButton variant="ghost" size="sm" :icon="Archive" :aria-label="t('unit.archiveNamed', { name: row.name })" @click="archive(row)" />
            </UiTooltip>
          </div>
        </template>
      </UiTable>
    </UiCard>

    <UiDialog v-model:open="dialog.open" :title="t(titles[dialog.mode])" :description="dialog.unit && dialog.mode !== 'create' ? dialog.unit.name : undefined" size="sm">
      <form id="unit-form" class="flex flex-col gap-4" @submit.prevent="save">
        <template v-if="dialog.mode !== 'move'">
          <UiField v-slot="{ id }" :label="t('unit.name')" required>
            <UiInput :id="id" v-model="dialog.name" data-testid="unit-form-name" />
          </UiField>
          <UiField v-slot="{ id }" :label="t('unit.kind')" :hint="t('unit.kindHint')" required>
            <UiInput :id="id" v-model="dialog.kind" data-testid="unit-form-kind" />
          </UiField>
        </template>
        <UiField v-if="dialog.mode !== 'rename'" v-slot="{ id }" :label="t('unit.parent')">
          <UiSelect :id="id" v-model="dialog.parentId" :options="targets" />
        </UiField>
      </form>

      <template #footer>
        <UiButton @click="dialog.open = false">{{ t('common.cancel') }}</UiButton>
        <UiButton variant="primary" type="submit" form="unit-form" :loading="busy" data-testid="unit-form-save">{{ t('common.save') }}</UiButton>
      </template>
    </UiDialog>
  </div>
</template>
