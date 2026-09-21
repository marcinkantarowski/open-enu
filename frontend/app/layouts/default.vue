<script setup lang="ts">
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

async function signOut() {
  await logout()
  await navigateTo('/login')
}
</script>

<template>
  <div class="min-h-screen">
    <ImpersonationBanner @exit="navigateTo('/login')" />

    <div class="flex min-h-screen">
      <aside
        v-show="ui.sidebarOpen"
        class="w-60 shrink-0 border-r border-border bg-surface"
      >
        <div class="flex h-14 items-center px-5 text-sm font-semibold text-fg">
          {{ $config.public.appName }}
        </div>

        <nav class="flex flex-col gap-0.5 px-3 py-2">
          <NuxtLink
            v-for="item in items"
            :key="item.to"
            :to="item.to"
            class="rounded-lg px-3 py-2 text-sm text-fg-muted hover:bg-surface-sunken hover:text-fg"
            active-class="bg-brand-50 text-brand-700"
          >
            {{ item.label }}
          </NuxtLink>
        </nav>
      </aside>

      <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex h-14 items-center justify-between gap-4 border-b border-border bg-surface px-5">
          <button
            type="button"
            class="rounded-md px-2 py-1 text-fg-muted hover:bg-surface-sunken"
            :aria-label="t('common.actions')"
            :aria-expanded="ui.sidebarOpen"
            @click="ui.toggleSidebar()"
          >
            ☰
          </button>

          <div class="flex items-center gap-4">
            <RealtimeIndicator />
            <NotificationBell />
            <TenantSwitcher />

            <span class="text-sm text-fg-muted" data-testid="current-user">
              {{ auth.user?.displayName ?? auth.user?.email }}
            </span>

            <UiButton size="sm" variant="ghost" data-testid="sign-out" @click="signOut">
              {{ t('auth.signOut') }}
            </UiButton>
          </div>
        </header>

        <main class="min-w-0 flex-1 p-6">
          <slot />
        </main>
      </div>
    </div>
  </div>
</template>
