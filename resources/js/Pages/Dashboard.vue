<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import UrgencyBadge from '@/Components/UrgencyBadge.vue';
import CategoryPill from '@/Components/CategoryPill.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  stats: { type: Object, required: true },
  sync_state: { type: Object, default: null },
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
</script>

<template>
  <div class="max-w-5xl mx-auto px-8 py-8">
    <div class="flex items-end justify-between mb-6">
      <div>
        <h1 class="text-xl font-semibold tracking-tight">Inbox overview</h1>
        <p class="text-sm text-ink-soft mt-0.5">
          Last polled
          <span class="font-mono-tabular">{{ timeAgo(sync_state?.last_polled_at) }}</span>
          <span v-if="sync_state?.status === 'error'" class="text-urgency-critical ml-2">
            · sync error: {{ sync_state.last_error }}
          </span>
        </p>
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
