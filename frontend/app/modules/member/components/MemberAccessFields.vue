<script setup lang="ts">
import type { RoleOption, UnitOption } from '../types/access'

/**
 * A role and a reach, edited together - they are one decision about what
 * someone may see and do (ADR-0025). The reach is the whole tenant, or some
 * units, each with everything below it.
 */
const role = defineModel<string>('role', { required: true })
const unitIds = defineModel<string[] | null>('unitIds', { required: true })

const props = defineProps<{ roles: RoleOption[], units: UnitOption[] }>()
const { t } = useI18n()

const roleOptions = computed(() => props.roles.map(r => ({ value: r.key, label: r.name ?? t(`role.key.${r.key}`) })))

const reach = computed({
  get: () => (unitIds.value === null ? 'all' : 'some'),
  set: (value: string) => { unitIds.value = value === 'all' ? null : [] },
})

function toggle(id: string, on: boolean | 'indeterminate') {
  const current = new Set(unitIds.value ?? [])
  if (on === true) current.add(id)
  else current.delete(id)
  unitIds.value = [...current]
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <UiField v-slot="{ id }" :label="t('member.role')" required>
      <UiSelect :id="id" v-model="role" :options="roleOptions" data-testid="member-form-role" />
    </UiField>

    <UiRadioGroup
      v-if="units.length"
      v-model="reach"
      :label="t('member.reach')"
      :options="[
        { value: 'all', label: t('member.reachAll') },
        { value: 'some', label: t('member.reachSome'), description: t('member.reachSomeHint') },
      ]"
    />

    <fieldset v-if="unitIds !== null" class="flex flex-col gap-2 pl-1">
      <legend class="sr-only">{{ t('member.reachSome') }}</legend>
      <div v-for="unit in units.filter(u => !u.archived)" :key="unit.id" :style="{ paddingInlineStart: `${unit.depth * 1.25}rem` }">
        <UiCheckbox
          :model-value="unitIds.includes(unit.id)"
          :label="unit.name"
          :data-testid="`member-unit-${unit.name}`"
          @update:model-value="toggle(unit.id, $event)"
        />
      </div>
    </fieldset>
  </div>
</template>
