<script setup>
defineProps({
  logs: { type: Array, default: () => [] },
});

const STAGE_LABEL = {
  anonymize: 'Anonymize',
  triage: 'Triage',
  embed: 'Embed',
};

// Dot color per status. The palette has no "danger" token, so failure reuses
// urgency-critical (the same red ActionButton uses for its danger variant).
const STATUS_DOT = {
  succeeded: 'bg-accent',
  failed: 'bg-urgency-critical',
  started: 'bg-ink-faint',
  skipped: 'bg-ink-faint',
};

const STATUS_BADGE = {
  succeeded: 'text-accent',
  failed: 'text-urgency-critical',
  started: 'text-ink-soft',
  skipped: 'text-ink-faint',
};

function formatDuration(ms) {
  const seconds = ms / 1000;
  if (seconds >= 60) {
    const minutes = Math.floor(seconds / 60);
    const secs = Math.round(seconds % 60);
    return `${minutes}m ${secs}s`;
  }
  return `${seconds.toFixed(2)}s`;
}

function formatDateTime(iso) {
  return new Date(iso).toLocaleString(undefined, {
    month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
  });
}

function pretty(payload) {
  return payload ? JSON.stringify(payload, null, 2) : '';
}
</script>

<template>
  <div class="mt-6">
    <h2 class="text-xs font-medium text-ink-soft uppercase tracking-wide mb-2">Processing log</h2>
    <ul class="relative border-l border-ink-faint/20 ml-2 pl-4 space-y-4">
      <li v-for="log in logs" :key="log.id">
        <details class="group">
          <summary
            class="relative flex flex-wrap items-center gap-x-3 gap-y-1 text-xs cursor-pointer list-none -ml-[21px] pl-[21px] [&::-webkit-details-marker]:hidden"
          >
            <span
              class="absolute left-0 w-2.5 h-2.5 rounded-full border border-background"
              :class="STATUS_DOT[log.status]"
            />

            <span class="font-mono text-ink-faint whitespace-nowrap">
              {{ formatDateTime(log.recorded_at) }}
            </span>

            <span class="font-medium text-ink-base">{{ STAGE_LABEL[log.stage] }}</span>

            <span
              class="px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wider font-medium bg-surface-sunken"
              :class="STATUS_BADGE[log.status]"
            >
              {{ log.status }}
            </span>

            <span v-if="log.attempt > 1" class="text-ink-faint">att. {{ log.attempt }}</span>

            <span v-if="log.duration_ms != null" class="text-ink-faint font-mono-tabular">
              {{ formatDuration(log.duration_ms) }}
            </span>

            <span
              v-if="log.message || log.payload"
              class="text-ink-faint transition-transform group-open:rotate-90"
            >›</span>
          </summary>

          <div v-if="log.message || log.payload" class="mt-2 ml-2 space-y-2">
            <p v-if="log.message" class="text-xs text-ink-soft">{{ log.message }}</p>
            <pre
              v-if="log.payload"
              class="bg-surface-sunken text-ink-soft text-[11px] leading-relaxed overflow-x-auto rounded p-3"
            >{{ pretty(log.payload) }}</pre>
          </div>
        </details>
      </li>
    </ul>
  </div>
</template>
