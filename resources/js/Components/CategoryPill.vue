<script setup>
defineProps({
  name: { type: String, required: true },
  pending: { type: Boolean, default: false },
});
</script>

<template>
  <span
    class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium border"
    :class="pending
      ? 'border-dashed border-ink-faint text-ink-soft'
      : 'border-border bg-surface-sunken text-ink-soft'"
  >
    {{ name }}
    <span v-if="pending" class="text-[10px] uppercase tracking-wide text-ink-faint">proposed</span>
  </span>
</template>
