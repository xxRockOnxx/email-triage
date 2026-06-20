<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  config: { type: Object, required: true },
});

const rows = [
  { key: 'triage_backend', label: 'Triage LLM backend' },
  { key: 'triage_model', label: 'Triage model' },
  { key: 'embedding_backend', label: 'Embedding backend' },
  { key: 'poll_cron', label: 'Gmail poll schedule (cron)' },
  { key: 'default_confidence_threshold', label: 'Default auto-file threshold' },
  { key: 'presidio_score_threshold', label: 'Presidio detection threshold' },
];
</script>

<template>
  <div class="max-w-2xl mx-auto px-8 py-8">
    <h1 class="text-xl font-semibold tracking-tight mb-1">Settings</h1>
    <p class="text-sm text-ink-soft mb-6">
      These are set via <code class="font-mono-tabular bg-surface-sunken px-1 py-0.5 rounded">.env</code>
      and config files, not editable here — changing them requires a queue worker restart.
    </p>

    <div class="bg-surface border border-border rounded-lg overflow-hidden">
      <dl class="divide-y divide-border">
        <div v-for="row in rows" :key="row.key" class="flex items-center justify-between px-4 py-3">
          <dt class="text-sm text-ink-soft">{{ row.label }}</dt>
          <dd class="text-sm font-mono-tabular text-ink">{{ config[row.key] ?? '—' }}</dd>
        </div>
      </dl>
    </div>
  </div>
</template>
