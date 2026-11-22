import { defineSearchTargets } from '~/composables/useGlobalSearch'

// A project found by its name or description (backend `search.php`). The module
// has one page, so a hit opens the list the project is on.
export default defineSearchTargets({
  'App\\Module\\Example\\Entity\\Project': { group: 'example.search.group', to: () => '/example' },
})
