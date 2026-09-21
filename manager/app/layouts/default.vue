<script setup lang="ts">
import { useAuthStore } from '@ui-kit/stores/auth'

const auth = useAuthStore()
const { logout } = useOperator()
const { t } = useI18n()

const links = computed(() => [
  { to: '/', label: t('manager.tenants') },
  { to: '/audit', label: t('manager.audit') },
  { to: '/flags', label: t('manager.flags') },
  { to: '/workers', label: t('manager.workers') },
])

function signOut() {
  logout()
  return navigateTo('/login')
}
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <!-- Visually distinct from the tenant app on purpose. An operator with both
         open must never have to read the URL to know which one they are in. -->
    <header class="flex h-14 items-center justify-between gap-4 bg-fg px-5 text-white">
      <div class="flex items-center gap-6">
        <span class="text-sm font-semibold">{{ $config.public.appName }}</span>

        <nav class="flex gap-1">
          <NuxtLink
            v-for="link in links"
            :key="link.to"
            :to="link.to"
            class="rounded-md px-3 py-1.5 text-sm text-white/70 hover:bg-white/10 hover:text-white"
            active-class="bg-white/15 text-white"
          >
            {{ link.label }}
          </NuxtLink>
        </nav>
      </div>

      <div class="flex items-center gap-4">
        <span class="text-sm text-white/70">{{ auth.user?.displayName ?? auth.user?.email }}</span>
        <button type="button" class="text-sm text-white/70 hover:text-white" @click="signOut">
          {{ t('auth.signOut') }}
        </button>
      </div>
    </header>

    <main class="flex-1 p-6">
      <slot />
    </main>
  </div>
</template>
