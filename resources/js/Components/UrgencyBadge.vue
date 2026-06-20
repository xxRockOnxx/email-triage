<script setup>
import { useUrgency } from '@/composables/useUrgency';

const props = defineProps({
  urgency: { type: String, required: true },
});

const { config } = useUrgency();
</script>

<template>
  <span
    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium font-mono-tabular uppercase tracking-wide"
    :class="config(urgency).badgeClass"
  >
    {{ config(urgency).label }}
  </span>
</template>
