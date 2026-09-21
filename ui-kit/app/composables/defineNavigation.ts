/**
 * A module contributes menu entries by exporting these from its layer.
 *
 * The shell merges and permission-filters them, so adding a feature never means
 * editing a central navigation file - which is the thing that makes two modules
 * conflict in a merge (.ai/platform/PLAN.md §6.10).
 */
export interface NavigationItem {
  /** Translation key, never a literal - raw strings fail lint (ADR-0020). */
  label: string
  to: string
  icon?: string
  /** Hidden unless the session grants it. The server still enforces it. */
  permission?: string
  order?: number
}

export function defineNavigation(items: NavigationItem[]): NavigationItem[] {
  return items
}

export interface SettingsTab {
  label: string
  to: string
  permission?: string
  order?: number
}

export function defineSettingsTab(tabs: SettingsTab[]): SettingsTab[] {
  return tabs
}
