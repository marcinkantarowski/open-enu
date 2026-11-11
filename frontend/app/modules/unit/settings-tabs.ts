import { defineSettingsTab } from '@ui-kit/composables/defineNavigation'

export default defineSettingsTab([
  { label: 'unit.title', to: '/settings/structure', permission: 'unit.view', order: 20 },
])
