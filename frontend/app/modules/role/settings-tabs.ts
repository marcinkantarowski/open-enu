import { defineSettingsTab } from '@ui-kit/composables/defineNavigation'

export default defineSettingsTab([
  { label: 'role.title', to: '/settings/roles', permission: 'role.view', order: 30 },
])
