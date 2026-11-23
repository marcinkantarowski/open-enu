import type { NavigationItem, SettingsTab } from '@ui-kit/composables/defineNavigation'
import { useAuthStore } from '@ui-kit/stores/auth'

/**
 * The menu, assembled from the modules rather than written down.
 *
 * Each module layer exports `navigation.ts`; this glob finds them at build time.
 * The alternative - a central array listing every module - is the file two
 * feature branches both edit, and the file someone forgets, producing a page
 * that exists but cannot be reached.
 *
 * `eager: true` because the menu is needed on first paint; lazy chunks here
 * would make the sidebar pop in after the content.
 */
const navigationModules = import.meta.glob<{ default: NavigationItem[] }>(
  '../modules/*/navigation.ts',
  { eager: true },
)

const settingsModules = import.meta.glob<{ default: SettingsTab[] }>(
  '../modules/*/settings-tabs.ts',
  { eager: true },
)

/** The settings tabs a session may open, in order - labels still keys. */
function visibleSettingsTabs(can: (permission: string) => boolean): SettingsTab[] {
  return Object.values(settingsModules)
    .flatMap(mod => mod.default ?? [])
    .filter(tab => !tab.permission || can(tab.permission))
    .sort((a, b) => (a.order ?? 100) - (b.order ?? 100))
}

/**
 * Where "Settings" opens for this session: the first tab it may see. The
 * workspace screen at /settings needs settings.view, and someone without it
 * still has their profile and whatever else their role reads - the menu's
 * own entry answering 403 was the alternative.
 */
export function settingsHome(): string {
  const auth = useAuthStore()
  return visibleSettingsTabs(permission => auth.can(permission))[0]?.to ?? '/settings/profile'
}

export function useNavigation() {
  const auth = useAuthStore()
  const { t } = useI18n()
  const { public: cfg } = useRuntimeConfig()

  const translate = <T extends { label: string }>(item: T): T => ({ ...item, label: t(item.label) })

  const items = computed<NavigationItem[]>(() =>
    Object.values(navigationModules)
      .flatMap(mod => mod.default ?? [])
      // Hidden, not disabled: an entry someone cannot use is noise, and the
      // server refuses the request regardless of what renders here.
      .filter(item => !item.permission || auth.can(item.permission))
      // The reference module is the platform showing itself off. A product
      // keeps the module - tests stand on it - and stops advertising it.
      .filter(item => !item.demo || cfg.demoContent)
      .sort((a, b) => (a.order ?? 100) - (b.order ?? 100))
      .map(translate),
  )

  const settingsTabs = computed<SettingsTab[]>(() =>
    visibleSettingsTabs(permission => auth.can(permission)).map(translate),
  )

  return { items, settingsTabs }
}
