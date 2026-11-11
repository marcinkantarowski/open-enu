<script setup lang="ts">
import { LayoutDashboard, LogOut, Menu } from '@lucide/vue'
import { useAuthStore } from '@ui-kit/stores/auth'
import { useUiStore } from '@ui-kit/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const { items } = useNavigation()
const { logout } = useAuth()
const { t } = useI18n()
const { connect } = useMercure()

// One stream per tab, opened once the shell mounts. Widgets subscribe to it by
// event name; they do not each open a connection.
onMounted(() => { void connect() })

// Initials stand in for a picture nobody uploaded: enough to tell at a glance
// whose session this tab holds.
const initials = computed(() => {
  const source = auth.user?.displayName || auth.user?.email || ''
  const parts = source.split(/[\s.@_-]+/).filter(Boolean)

  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?'
})

async function signOut() {
  await logout()
  await navigateTo('/login')
}
</script>

<template>
  <div class="min-h-screen">
    <!-- The first thing Tab reaches: past the menu, straight to the page. -->
    <a
      href="#main"
      class="sr-only z-50 rounded-control bg-surface px-4 py-2 text-sm font-medium text-fg shadow-raised focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
    >{{ t('shell.skipToContent') }}</a>

    <ImpersonationBanner @exit="navigateTo('/login')" />

    <div class="flex min-h-screen">
      <aside
        v-show="ui.sidebarOpen"
        class="sticky top-0 flex h-screen w-64 shrink-0 flex-col border-r border-nav-border bg-nav"
      >
        <NuxtLink to="/" class="flex h-14 shrink-0 items-center border-b border-nav-border px-5">
          <BrandMark :name="String($config.public.appName)" tone="nav" />
        </NuxtLink>

        <nav class="flex flex-1 flex-col gap-0.5 overflow-y-auto px-3 py-4" :aria-label="t('app.nav.main')">
          <NuxtLink
            to="/"
            class="flex h-control-md items-center gap-3 rounded-control px-3 text-sm text-nav-fg transition-colors hover:bg-nav-hover"
            exact-active-class="bg-nav-active font-medium text-nav-fg-active hover:bg-nav-active"
          >
            <LayoutDashboard class="size-5 shrink-0" aria-hidden="true" />
            {{ t('shell.dashboard') }}
          </NuxtLink>
          <NuxtLink
            v-for="item in items"
            :key="item.to"
            :to="item.to"
            class="flex h-control-md items-center gap-3 rounded-control px-3 text-sm text-nav-fg transition-colors hover:bg-nav-hover"
            active-class="bg-nav-active font-medium text-nav-fg-active hover:bg-nav-active"
          >
            <component :is="item.icon" v-if="item.icon" class="size-5 shrink-0" aria-hidden="true" />
            <span v-else class="size-5 shrink-0" aria-hidden="true" />
            {{ item.label }}
          </NuxtLink>
        </nav>
      </aside>

      <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-10 flex h-14 items-center justify-between gap-4 border-b border-border bg-surface px-6">
          <UiButton
            variant="ghost"
            :icon="Menu"
            :aria-label="t('app.nav.menu')"
            :aria-expanded="ui.sidebarOpen"
            @click="ui.toggleSidebar()"
          />

          <div class="flex items-center gap-3 whitespace-nowrap">
            <RealtimeIndicator />
            <NotificationBell />
            <TenantSwitcher />

            <span class="hidden h-6 w-px bg-border sm:block" aria-hidden="true" />

            <span class="flex items-center gap-2.5">
              <span
                class="inline-flex size-8 items-center justify-center rounded-full bg-selected text-xs font-semibold text-accent-700"
                aria-hidden="true"
              >{{ initials }}</span>
              <span class="hidden text-sm font-medium text-fg sm:inline" data-testid="current-user">
                {{ auth.user?.displayName ?? auth.user?.email }}
              </span>
            </span>

            <UiButton size="sm" variant="ghost" :icon="LogOut" data-testid="sign-out" @click="signOut">
              {{ t('auth.signOut') }}
            </UiButton>
          </div>
        </header>

        <main id="main" tabindex="-1" class="mx-auto w-full min-w-0 max-w-7xl flex-1 px-6 py-8 focus:outline-none">
          <slot />
        </main>
      </div>
    </div>
  </div>
</template>
