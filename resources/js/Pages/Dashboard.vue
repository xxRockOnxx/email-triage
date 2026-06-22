<script setup>
import { Link, router, usePoll } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import UrgencyBadge from '@/Components/UrgencyBadge.vue';
import CategoryPill from '@/Components/CategoryPill.vue';
import { computed } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  stats: { type: Object, required: true },
  sync_state: { type: Object, default: null },
  next_poll_at: { type: String, default: null },
  recent_needs_review: { type: Array, required: true },
});

function timeAgo(iso) {
  if (!iso) return '—';
  const diffMin = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  if (diffMin < 1) return 'just now';
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHr = Math.round(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h ago`;
  return `${Math.round(diffHr / 24)}d ago`;
}

const isPolling = computed(() => props.sync_state?.status === 'polling');

function pollNow() {
    router.post(route('dashboard.poll'), {}, {
        preserveScroll: true,
    });
}

usePoll(1000)
</script>

<template>
  <div class="max-w-5xl mx-auto px-8 py-8">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6 pb-4 border-b border-stroke-soft">
      <!-- Left Side: Title & Action -->
      <div class="flex items-center gap-4">
        <h1 class="text-xl font-semibold tracking-tight text-ink">Inbox overview</h1>

        <!-- Poll Now Button -->
        <button
          class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md border border-stroke text-ink hover:bg-surface-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          :disabled="isPolling"
          @click="pollNow"
        >
          <!-- Heroicon: ArrowPath (Sync/Refresh) -->
          <svg
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="2"
            stroke="currentColor"
            class="w-3.5 h-3.5"
            :class="{ 'animate-spin': syncState?.status === 'polling' }"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
          </svg>
          <span>{{ syncState?.status === 'polling' ? 'Polling...' : 'Poll now' }}</span>
        </button>
      </div>

      <!-- Right Side: System Metadata Statuses -->
      <div class="flex items-center gap-3 text-xs md:text-sm font-mono-tabular text-ink-soft md:text-right">
        <!-- Error Alert takes precedence -->
        <div v-if="syncState?.status === 'error'" class="text-urgency-critical bg-red-50 px-2 py-1 rounded border border-red-100">
          Error: {{ syncState.last_error }}
        </div>

        <!-- Standard Meta -->
        <div v-else>
          <div>Polled {{ timeAgo(sync_state?.last_polled_at) }}</div>
          <div v-if="next_poll_at && syncState?.status !== 'polling'" class="text-[11px] opacity-70 mt-0.5">
            Next: {{ new Date(next_poll_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
      <StatCard
        label="Needs review"
        :value="stats.needs_review_count"
        tone="warning"
        :href="route('emails.index', { status: 'needs_review' })"
      />
      <StatCard
        label="Auto-filed"
        :value="stats.auto_filed_count"
        :href="route('emails.index', { status: 'auto_filed' })"
      />
      <StatCard
        label="Critical, unactioned"
        :value="stats.critical_urgency_count"
        :tone="stats.critical_urgency_count > 0 ? 'critical' : 'default'"
        :href="route('emails.index', { urgency: 'critical' })"
      />
      <StatCard
        label="Pending categories"
        :value="stats.pending_categories"
        :href="route('categories.index')"
      />
    </div>

    <div class="bg-surface border border-border rounded-lg overflow-hidden">
      <div class="px-5 py-3 border-b border-border flex items-center justify-between">
        <h2 class="text-sm font-semibold">Needs your review</h2>
        <Link
          :href="route('emails.index', { status: 'needs_review' })"
          class="text-xs text-accent hover:underline"
        >
          View all
        </Link>
      </div>

      <div v-if="recent_needs_review.length === 0" class="px-5 py-10 text-center text-sm text-ink-faint">
        Nothing waiting on you right now.
      </div>

      <ul v-else class="divide-y divide-border">
        <li v-for="email in recent_needs_review" :key="email.id">
          <Link
            :href="route('emails.show', email.id)"
            class="flex items-stretch gap-3 px-5 py-3 hover:bg-surface-sunken transition-colors"
          >
            <div
              class="urgency-spine"
              :class="`urgency-spine--${email.latest_triage_result?.urgency ?? 'low'}`"
            />
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-0.5">
                <span class="text-sm font-medium text-ink truncate">{{ email.sender_name || email.sender_email }}</span>
                <CategoryPill
                  v-if="email.latest_triage_result?.category"
                  :name="email.latest_triage_result.category.name"
                />
                <CategoryPill
                  v-else-if="email.latest_triage_result?.proposed_category_name"
                  :name="email.latest_triage_result.proposed_category_name"
                  pending
                />
              </div>
              <p class="text-sm text-ink-soft truncate">{{ email.latest_triage_result?.summary }}</p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
              <UrgencyBadge :urgency="email.latest_triage_result?.urgency ?? 'low'" />
            </div>
          </Link>
        </li>
      </ul>
    </div>
  </div>
</template>
