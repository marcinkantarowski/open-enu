<script setup lang="ts">
import { useAuthStore } from '@ui-kit/stores/auth'

/**
 * The dashboard.
 *
 * Deliberately a real page rather than a placeholder: it shows the session the
 * platform actually established - workspace, role, what this role may do - which
 * is the first thing anyone building on this boilerplate needs to see working,
 * and the first thing that breaks when scoping or permissions are wired wrongly.
 */
const auth = useAuthStore()
const { items } = useNavigation()
const { t } = useI18n()

// The live feed, fed by anything the backend marks #[ClientBroadcast]. It is
// tenant-scoped at the Mercure topic, so another tenant's events cannot appear
// here even if this page asked for them.
const feed = ref<Array<{ event: string, at: string, subject: string }>>([])

useAppEvent('example.project.created', (message) => {
  feed.value = [
    { event: message.event, at: message.occurredAt, subject: message.subjectId },
    ...feed.value,
  ].slice(0, 10)
})
</script>

<template>
  <div class="flex flex-col gap-6">
    <div>
      <h1 class="text-lg font-semibold text-fg">
        {{ t('app.dashboard.greeting', { name: auth.user?.displayName ?? auth.user?.email ?? '' }) }}
      </h1>
      <p class="text-sm text-fg-muted">{{ t('app.dashboard.subtitle') }}</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <UiCard :title="t('app.dashboard.session')">
        <dl class="grid grid-cols-[8rem_1fr] gap-y-2 text-sm">
          <dt class="text-fg-muted">{{ t('tenant.current') }}</dt>
          <dd class="text-fg" data-testid="current-tenant">{{ auth.tenantName ?? auth.tenantId ?? '-' }}</dd>

          <dt class="text-fg-muted">{{ t('app.dashboard.role') }}</dt>
          <dd><UiBadge tone="brand">{{ auth.role ?? '-' }}</UiBadge></dd>

          <dt class="text-fg-muted">{{ t('app.dashboard.permissions') }}</dt>
          <dd class="text-fg">{{ auth.permissions.length }}</dd>
        </dl>
      </UiCard>

      <UiCard :title="t('app.dashboard.modules')" :description="t('app.dashboard.modulesHint')">
        <ul class="flex flex-col gap-1 text-sm">
          <li v-for="item in items" :key="item.to">
            <NuxtLink :to="item.to" class="text-brand-700 hover:underline">{{ item.label }}</NuxtLink>
          </li>
        </ul>
      </UiCard>
    </div>

    <UiCard :title="t('app.dashboard.live')" :description="t('app.dashboard.liveHint')">
      <template #header>
        <RealtimeIndicator />
      </template>

      <UiEmptyState v-if="feed.length === 0" :title="t('app.dashboard.liveEmpty')" :description="t('app.dashboard.liveEmptyHint')" />

      <ul v-else class="flex flex-col gap-1 text-xs" data-testid="live-feed">
        <li v-for="(entry, index) in feed" :key="`${entry.at}-${index}`" class="font-mono text-fg">
          {{ entry.at }} · {{ entry.event }} · {{ entry.subject }}
        </li>
      </ul>
    </UiCard>

    <!-- Any module may add a widget here without this file knowing it exists. -->
    <InjectionPoint name="dashboard.widgets" />
  </div>
</template>
