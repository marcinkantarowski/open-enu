import { Settings } from '@lucide/vue'
import { defineNavigation } from '@ui-kit/composables/defineNavigation'

// No permission: every session has at least one settings tab - its own
// profile - and /settings sends anyone without settings.view to the first
// tab they may open.
export default defineNavigation([
  { label: 'settings.title', to: '/settings', order: 90, icon: Settings },
])
