<script setup>
const props = defineProps({
  confidence: { type: Number, required: true },
});

function colorClass(value) {
  if (value >= 75) return 'bg-accent';
  if (value >= 50) return 'bg-urgency-medium';
  return 'bg-urgency-high';
}
</script>

<template>
  <div class="flex items-center gap-2 w-24">
    <div class="flex-1 h-1.5 rounded-full bg-surface-sunken overflow-hidden">
      <div
        class="h-full rounded-full transition-all"
        :class="colorClass(confidence)"
        :style="{ width: `${confidence}%` }"
      />
    </div>
    <span class="font-mono-tabular text-xs text-ink-soft tabular-nums w-9 text-right">{{ confidence }}%</span>
  </div>
</template>
