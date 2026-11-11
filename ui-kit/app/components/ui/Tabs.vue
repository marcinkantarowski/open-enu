<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui'

/**
 * Sections of one record or one screen, one visible at a time. Each tab's panel
 * is the slot of the same name: `<template #details>` for `{ value: 'details' }`.
 *
 * Arrow keys move between tabs and Tab moves into the panel, which is what a
 * screen reader announces a tablist as promising.
 */
const model = defineModel<string>()

const props = defineProps<{
  tabs: Array<{ value: string, label: string, count?: number }>
  label: string
}>()

const active = computed({
  get: () => model.value ?? props.tabs[0]?.value ?? '',
  set: (value: string) => { model.value = value },
})
</script>

<template>
  <TabsRoot v-model="active" class="flex flex-col gap-5">
    <TabsList :aria-label="props.label" class="flex gap-1 overflow-x-auto border-b border-border">
      <TabsTrigger
        v-for="tab in props.tabs"
        :key="tab.value"
        :value="tab.value"
        class="-mb-px inline-flex h-control-md items-center gap-2 whitespace-nowrap border-b-2 border-transparent px-3 text-sm font-medium text-fg-muted transition-colors hover:text-fg data-[state=active]:border-accent data-[state=active]:text-fg"
      >
        {{ tab.label }}
        <span v-if="tab.count !== undefined" class="rounded-sm bg-surface-subtle px-1.5 text-xs tabular-nums text-fg-muted">{{ tab.count }}</span>
      </TabsTrigger>
    </TabsList>

    <TabsContent v-for="tab in props.tabs" :key="tab.value" :value="tab.value" class="focus-visible:outline-offset-4">
      <slot :name="tab.value" />
    </TabsContent>
  </TabsRoot>
</template>
