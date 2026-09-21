<script setup lang="ts">
const { public: cfg } = useRuntimeConfig()
const { t } = useI18n()
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-fg p-6">
    <div class="w-full max-w-sm">
      <p class="mb-6 text-center text-sm font-semibold text-white">{{ cfg.appName }}</p>
      <UiCard>
        <slot />
      </UiCard>
      <p class="mt-4 text-center text-xs text-white/50">{{ t('manager.loginNotice') }}</p>
    </div>
  </div>
</template>
