<script setup lang="ts">
import { Pencil, Plus, RotateCcw, UserX } from '@lucide/vue'
import type { RoleOption, UnitOption } from '../../types/access'

definePageMeta({ middleware: 'permission', permission: 'member.view' })

/**
 * The tenant's people (ADR-0025). The server decides who may change whom -
 * nobody hands out more than they hold - so this page offers the controls the
 * session allows and shows the refusal when the server disagrees.
 */
interface Member {
  id: string
  email: string
  displayName: string | null
  role: string
  unitIds: string[] | null
  status: 'active' | 'invited' | 'suspended'
  version: number
}

const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const { t } = useI18n()

const canInvite = computed(() => auth.can('member.invite'))
const canManage = computed(() => auth.can('member.manage'))

const { data, refresh } = await useAsyncData('members', () => api.get<{ items: Member[] }>('/api/members'))
const members = computed(() => data.value?.items ?? [])

// Names for keys and ids. Each list is read only by someone allowed to: without
// it the page still works, showing a role's key and a count of units.
const { data: roleData } = await useAsyncData('member-roles', () =>
  auth.can('role.view') ? api.get<{ items: RoleOption[] }>('/api/roles') : Promise.resolve({ items: [] as RoleOption[] }),
)
const { data: unitData } = await useAsyncData('member-units', () =>
  auth.can('unit.view') ? api.get<{ items: UnitOption[] }>('/api/units') : Promise.resolve({ items: [] as UnitOption[] }),
)
// Someone who may invite but not read roles still picks from the built-in ones.
const BUILT_IN: RoleOption[] = ['owner', 'admin', 'member'].map(key => ({ key, name: null }))
const roles = computed(() => (roleData.value?.items.length ? roleData.value.items : BUILT_IN))
const units = computed(() => unitData.value?.items ?? [])

function roleName(key: string): string {
  const role = roles.value.find(r => r.key === key)
  return role?.name ?? (['owner', 'admin', 'member'].includes(key) ? t(`role.key.${key}`) : key)
}

function reachOf(member: Member): string {
  if (member.unitIds === null) return t('member.allUnits')
  if (member.unitIds.length === 0) return t('member.noUnits')
  const names = member.unitIds.map(id => units.value.find(u => u.id === id)?.name).filter(Boolean)
  return names.length ? names.join(', ') : String(member.unitIds.length)
}

const tones = { active: 'ok', invited: 'info', suspended: 'warn' } as const

const dialog = reactive({ open: false, member: null as Member | null, email: '', role: 'member', unitIds: null as string[] | null })
const busy = ref(false)

function open(member: Member | null = null) {
  Object.assign(dialog, {
    open: true,
    member,
    email: '',
    role: member?.role ?? 'member',
    unitIds: member ? (member.unitIds === null ? null : [...member.unitIds]) : null,
  })
}

async function save() {
  busy.value = true
  try {
    if (dialog.member) {
      await api.post(`/api/members/${dialog.member.id}/access`, { role: dialog.role, unitIds: dialog.unitIds, version: dialog.member.version })
      toast.success(t('member.saved'))
    } else {
      await api.post('/api/members', { email: dialog.email, role: dialog.role, unitIds: dialog.unitIds })
      toast.success(t('member.invited'))
    }
    dialog.open = false
    await refresh()
  } catch (error) {
    toast.error((error as Error).message)
  } finally {
    busy.value = false
  }
}

async function act(member: Member, action: 'suspend' | 'reactivate') {
  if (action === 'suspend' && !confirm(t('member.suspendConfirm', { name: nameOf(member) }))) return

  try {
    await api.post(`/api/members/${member.id}/${action}`)
    toast.success(t('member.saved'))
    await refresh()
  } catch (error) {
    toast.error((error as Error).message)
  }
}

function nameOf(member: Member): string {
  return member.displayName ?? member.email
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <SettingsTabs />

    <UiCard :title="t('member.title')" :description="t('member.hint')">
      <template #header>
        <UiButton v-if="canInvite" variant="primary" size="sm" :icon="Plus" data-testid="member-invite" @click="open()">
          {{ t('member.invite') }}
        </UiButton>
      </template>

      <UiTable
        :caption="t('member.title')"
        :columns="[
          { key: 'name', label: t('member.name') },
          { key: 'role', label: t('member.role') },
          { key: 'units', label: t('member.units') },
          { key: 'status', label: t('member.state') },
          { key: 'actions', label: '' },
        ]"
        :rows="members"
        :empty-title="t('member.empty')"
      >
        <template #cell-name="{ row }">
          <div class="flex flex-col" data-testid="member-row">
            <span class="font-medium">{{ nameOf(row) }}</span>
            <span v-if="row.displayName" class="text-xs text-fg-muted">{{ row.email }}</span>
          </div>
        </template>

        <template #cell-role="{ row }">{{ roleName(row.role) }}</template>
        <template #cell-units="{ row }">{{ reachOf(row) }}</template>

        <template #cell-status="{ row }">
          <UiStatus :tone="tones[row.status as Member['status']]">{{ t(`member.status.${row.status}`) }}</UiStatus>
        </template>

        <template #cell-actions="{ row }">
          <div v-if="canManage" class="flex justify-end gap-1">
            <UiTooltip :content="t('member.edit')">
              <UiButton variant="ghost" size="sm" :icon="Pencil" :aria-label="t('member.editNamed', { name: nameOf(row) })" @click="open(row)" />
            </UiTooltip>
            <UiTooltip v-if="row.status === 'suspended'" :content="t('member.reactivate')">
              <UiButton variant="ghost" size="sm" :icon="RotateCcw" :aria-label="t('member.reactivateNamed', { name: nameOf(row) })" @click="act(row, 'reactivate')" />
            </UiTooltip>
            <UiTooltip v-else :content="t('member.suspend')">
              <UiButton variant="ghost" size="sm" :icon="UserX" :aria-label="t('member.suspendNamed', { name: nameOf(row) })" @click="act(row, 'suspend')" />
            </UiTooltip>
          </div>
        </template>
      </UiTable>
    </UiCard>

    <UiDialog
      v-model:open="dialog.open"
      :title="dialog.member ? t('member.editNamed', { name: nameOf(dialog.member) }) : t('member.inviteTitle')"
      :description="dialog.member ? dialog.member.email : t('member.inviteHint')"
      size="md"
    >
      <form id="member-form" class="flex flex-col gap-4" @submit.prevent="save">
        <UiField v-if="!dialog.member" v-slot="{ id }" :label="t('member.email')" required>
          <UiInput :id="id" v-model="dialog.email" type="email" autocomplete="off" data-testid="member-form-email" />
        </UiField>
        <MemberAccessFields v-model:role="dialog.role" v-model:unit-ids="dialog.unitIds" :roles="roles" :units="units" />
      </form>

      <template #footer>
        <UiButton @click="dialog.open = false">{{ t('common.cancel') }}</UiButton>
        <UiButton variant="primary" type="submit" form="member-form" :loading="busy" data-testid="member-form-save">
          {{ dialog.member ? t('common.save') : t('member.invite') }}
        </UiButton>
      </template>
    </UiDialog>
  </div>
</template>
