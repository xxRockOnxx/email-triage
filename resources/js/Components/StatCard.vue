<script setup>
defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  tone: { type: String, default: 'default' }, // default | warning | critical
  href: { type: String, default: null },
});
</script>

<template>
  <component
    :is="href ? 'a' : 'div'"
    :href="href"
    class="block bg-surface border border-border rounded-lg px-5 py-4 transition-shadow"
    :class="href ? 'hover:shadow-sm cursor-pointer' : ''"
  >
    <div class="text-xs text-ink-soft mb-1.5">{{ label }}</div>
    <div
      class="text-2xl font-mono-tabular font-semibold tabular-nums"
      :class="{
        'text-urgency-critical': tone === 'critical',
        'text-urgency-medium': tone === 'warning',
        'text-ink': tone === 'default',
      }"
    >
      {{ value }}
    </div>
  </component>
</template>
