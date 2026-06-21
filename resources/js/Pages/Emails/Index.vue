<script setup>
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import debounce from "@/lib/debounce";
import AppLayout from '@/Layouts/AppLayout.vue';
import UrgencyBadge from '@/Components/UrgencyBadge.vue';
import CategoryPill from '@/Components/CategoryPill.vue';
import ConfidenceMeter from '@/Components/ConfidenceMeter.vue';
import Pagination from '@/Components/Pagination.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  emails: { type: Object, required: true }, // Laravel paginator
  filters: { type: Object, default: () => ({}) },
});

const searchTerm = ref(props.filters.q ?? '');

const STATUS_TABS = [
  { value: null, label: 'All' },
  { value: 'needs_review', label: 'Needs review' },
  { value: 'auto_filed', label: 'Auto-filed' },
  { value: 'corrected', label: 'Corrected' },
];

function applyFilter(key, value) {
  router.get(route('emails.index'), { ...props.filters, [key]: value }, {
    preserveState: true,
    replace: true,
  });
}

const debouncedSearch = debounce((value) => {
  applyFilter('q', value || undefined);
}, 350);

watch(searchTerm, (value) => debouncedSearch(value));

function formatDate(iso) {
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
</script>

<template>
  <div class="max-w-6xl mx-auto px-8 py-8">
    <div class="flex items-center justify-between mb-5">
      <h1 class="text-xl font-semibold tracking-tight">Triage queue</h1>

      <input
        v-model="searchTerm"
        type="search"
        placeholder="Search anonymized content…"
        class="w-64 px-3 py-1.5 text-sm border border-border rounded-md bg-surface focus:border-accent outline-none"
      />
    </div>

    <div class="flex items-center gap-1 mb-4 border-b border-border">
      <button
        v-for="tab in STATUS_TABS"
        :key="tab.label"
        class="px-3 py-2 text-sm -mb-px border-b-2 transition-colors"
        :class="filters.status === tab.value || (!filters.status && !tab.value)
          ? 'border-accent text-accent font-medium'
          : 'border-transparent text-ink-soft hover:text-ink'"
        @click="applyFilter('status', tab.value)"
      >
        {{ tab.label }}
      </button>
    </div>

    <div class="bg-surface border border-border rounded-lg overflow-hidden">
      <!-- Column labels: mirror each row's flex geometry so labels sit over their data -->
      <div
        v-if="emails.data.length"
        class="flex items-center gap-4 px-5 py-2.5 border-b border-border text-xs text-ink-faint uppercase tracking-wide"
      >
        <div class="urgency-spine" aria-hidden="true" />
        <div class="flex-1 font-medium">Sender</div>
        <div class="flex items-center gap-5 shrink-0">
          <span class="w-20 text-right font-medium">Confidence</span>
          <span class="w-20 text-center font-medium">Urgency</span>
          <span class="w-20 text-right font-medium">Received</span>
        </div>
      </div>

      <div v-if="emails.data.length === 0" class="px-5 py-14 text-center text-sm text-ink-faint">
        No emails match these filters.
      </div>

      <ul v-else class="divide-y divide-border">
        <li v-for="email in emails.data" :key="email.id">
          <Link
            :href="route('emails.show', email.id)"
            class="flex items-stretch gap-4 px-5 py-3.5 hover:bg-surface-sunken transition-colors"
          >
            <div
              class="urgency-spine"
              :class="`urgency-spine--${email.latest_triage_result?.urgency ?? 'low'}`"
            />

            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-1">
                <span class="text-sm font-medium text-ink truncate max-w-[220px]">
                  {{ email.sender_name || email.sender_email }}
                </span>
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
              <p class="text-sm text-ink-soft truncate">
                {{ email.latest_triage_result?.summary || 'Awaiting triage…' }}
              </p>
            </div>

            <div class="flex items-center gap-5 shrink-0">
              <div class="w-20 flex justify-end">
                <ConfidenceMeter v-if="email.latest_triage_result" :confidence="email.latest_triage_result.confidence" />
              </div>
              <div class="w-20 flex justify-center">
                <UrgencyBadge :urgency="email.latest_triage_result?.urgency ?? 'low'" />
              </div>
              <span class="text-xs text-ink-faint font-mono-tabular w-20 text-right">
                {{ formatDate(email.received_at) }}
              </span>
            </div>
          </Link>
        </li>
      </ul>
    </div>

    <Pagination :links="emails.links" />
  </div>
</template>
