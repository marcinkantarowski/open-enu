<script setup lang="ts">
import { Pencil, Plus, Trash2 } from '@lucide/vue'

definePageMeta({ middleware: 'permission', permission: 'role.view' })

/**
 * The tenant's roles (ADR-0025). A change applies to everyone holding the role
 * on their next request, so the list is re-read after each save rather than
 * patched. Boxes for what the editor does not hold are disabled: the server
 * refuses them anyway, and a box that cannot be ticked should look it.
 */
interface Role {
  key: string
  name: string | null
  builtIn: boolean
  editable: boolean
  permissions: string[]
  holders: number
  version: number | null
}

interface Group {
  module: string
  label: string
  permissions: { name: string, label: string }[]
}

const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()

const canManage = computed(() => auth.can('role.manage'))

const { data, refresh } = await useAsyncData('roles', () => api.get<{ items: Role[] }>('/api/roles'))
const roles = computed(() => data.value?.items ?? [])

// Only someone who can edit needs the catalogue - and only they may read it.
const { data: catalogue } = await useAsyncData('role-permissions', () =>
  canManage.value ? api.get<{ groups: Group[] }>('/api/roles/permissions') : Promise.resolve({ groups: [] }),
)
const groups = computed(() => catalogue.value?.groups ?? [])
const total = computed(() => groups.value.reduce((sum, group) => sum + group.permissions.length, 0))

function nameOf(role: Role): string {
  return role.name ?? t(`role.key.${role.key}`)
}

const dialog = reactive({ open: false, role: null as Role | null, name: '', granted: new Set<string>(), initial: new Set<string>() })
const busy = ref(false)

function open(role: Role | null = null) {
  Object.assign(dialog, {
    open: true,
    role,
    name: role?.name ?? '',
    granted: new Set(role?.permissions ?? []),
    initial: new Set(role?.permissions ?? []),
  })
}

function toggle(permission: string, on: boolean | 'indeterminate') {
  if (on === true) dialog.granted.add(permission)
  else dialog.granted.delete(permission)
}

// Adding what you do not hold is refused; taking it away is not.
function locked(permission: string): boolean {
  return !auth.can(permission) && !dialog.initial.has(permission)
}

async function save() {
  busy.value = true
  const permissions = [...dialog.granted]
  const name = dialog.name.trim() || null

  try {
    if (dialog.role) {
      await api.patch(`/api/roles/${dialog.role.key}`, { name, permissions }, {
        headers: { 'If-Match': `"${dialog.role.version}"` },
      })
    } else {
      await api.post('/api/roles', { name, permissions })
    }
    dialog.open = false
    toast.success(t('role.saved'))
    await refresh()
  } catch (error) {
    toast.error((error as Error).message)
  } finally {
    busy.value = false
  }
}

async function remove(role: Role) {
  if (!confirm(t('role.deleteConfirm', { name: nameOf(role) }))) return

  try {
    await api.del(`/api/roles/${role.key}`)
    toast.success(t('role.deleted'))
    await refresh()
  } catch (error) {
    toast.error((error as Error).message)
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <SettingsTabs />

    <UiCard :title="t('role.title')" :description="t('role.hint')">
      <template #header>
        <UiButton v-if="canManage" variant="primary" size="sm" :icon="Plus" data-testid="role-new" @click="open()">
          {{ t('role.add') }}
        </UiButton>
      </template>

      <UiTable
        :caption="t('role.title')"
        :columns="[
          { key: 'name', label: t('role.name') },
          { key: 'permissions', label: t('role.permissions') },
          { key: 'holders', label: t('role.holders') },
          { key: 'actions', label: '' },
        ]"
        :rows="roles"
      >
        <template #cell-name="{ row }">
          <span class="flex items-center gap-2" data-testid="role-name">
            {{ nameOf(row) }}
            <UiTag v-if="row.builtIn">{{ t('role.builtIn') }}</UiTag>
          </span>
        </template>

        <template #cell-permissions="{ row }">
          {{ row.editable && total ? t('role.permissionCount', { count: row.permissions.length, total }) : t('role.everything') }}
        </template>

        <template #cell-actions="{ row }">
          <div v-if="canManage && row.editable" class="flex justify-end gap-1">
            <UiTooltip :content="t('role.edit')">
              <UiButton variant="ghost" size="sm" :icon="Pencil" :aria-label="t('role.editNamed', { name: nameOf(row) })" data-testid="role-edit" @click="open(row)" />
            </UiTooltip>
            <UiTooltip v-if="!row.builtIn" :content="row.holders > 0 ? t('role.deleteHeld') : t('role.delete')">
              <UiButton variant="ghost" size="sm" :icon="Trash2" :disabled="row.holders > 0" :aria-label="t('role.deleteNamed', { name: nameOf(row) })" @click="remove(row)" />
            </UiTooltip>
          </div>
        </template>
      </UiTable>
    </UiCard>

    <UiDialog v-model:open="dialog.open" :title="dialog.role ? nameOf(dialog.role) : t('role.add')" :description="t('role.notYours')" size="lg">
      <form id="role-form" class="flex flex-col gap-5" @submit.prevent="save">
        <UiField v-slot="{ id }" :label="t('role.name')" :hint="dialog.role?.builtIn ? t('role.nameBuiltInHint') : undefined" :required="!dialog.role?.builtIn">
          <UiInput :id="id" v-model="dialog.name" :placeholder="dialog.role?.builtIn ? t(`role.key.${dialog.role.key}`) : undefined" data-testid="role-form-name" />
        </UiField>

        <fieldset v-for="group in groups" :key="group.module" class="flex flex-col gap-2">
          <legend class="mb-1 text-sm font-semibold text-fg">{{ group.label }}</legend>
          <UiCheckbox
            v-for="permission in group.permissions"
            :key="permission.name"
            :model-value="dialog.granted.has(permission.name)"
            :label="permission.label"
            :disabled="locked(permission.name)"
            :data-testid="`role-permission-${permission.name}`"
            @update:model-value="toggle(permission.name, $event)"
          />
        </fieldset>
      </form>

      <template #footer>
        <UiButton @click="dialog.open = false">{{ t('common.cancel') }}</UiButton>
        <UiButton variant="primary" type="submit" form="role-form" :loading="busy" data-testid="role-form-save">{{ t('common.save') }}</UiButton>
      </template>
    </UiDialog>
  </div>
</template>
