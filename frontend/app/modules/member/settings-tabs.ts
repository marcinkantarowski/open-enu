import { defineSettingsTab } from '@ui-kit/composables/defineNavigation'

export default defineSettingsTab([
  { label: 'member.title', to: '/settings/members', permission: 'member.view', order: 15 },
])
