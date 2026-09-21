import { defineNavigation } from '@ui-kit/composables/defineNavigation'

export default defineNavigation([
  { label: 'example.title', to: '/example', permission: 'example.view', order: 10 },
])
