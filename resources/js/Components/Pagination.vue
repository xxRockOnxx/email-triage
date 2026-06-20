<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
  links: { type: Array, required: true }, // Laravel paginator meta.links shape
});
</script>

<template>
  <nav v-if="links.length > 3" class="flex items-center gap-1 justify-center py-4">
    <template v-for="(link, i) in links" :key="i">
      <Link
        v-if="link.url"
        :href="link.url"
        v-html="link.label"
        class="px-3 py-1.5 rounded-md text-sm font-mono-tabular"
        :class="link.active
          ? 'bg-accent text-white'
          : 'text-ink-soft hover:bg-surface-sunken'"
        preserve-scroll
      />
      <span
        v-else
        v-html="link.label"
        class="px-3 py-1.5 rounded-md text-sm font-mono-tabular text-ink-faint"
      />
    </template>
  </nav>
</template>
