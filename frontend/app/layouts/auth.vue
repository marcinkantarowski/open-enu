<script setup lang="ts">
const { public: cfg } = useRuntimeConfig()
</script>

<template>
  <div class="flex min-h-screen items-center justify-center p-6">
    <div class="w-full max-w-sm">
      <p class="mb-6 text-center text-sm font-semibold text-fg">{{ cfg.appName }}</p>
      <UiCard>
        <slot />
      </UiCard>
    </div>
  </div>
</template>
