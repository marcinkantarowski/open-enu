<script setup lang="ts">
const { public: cfg } = useRuntimeConfig()

useHead({
  title: cfg.appName as string,
  htmlAttrs: { lang: 'en' },
})
</script>

<template>
  <div>
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>

    <ToastHost />
  </div>
</template>
