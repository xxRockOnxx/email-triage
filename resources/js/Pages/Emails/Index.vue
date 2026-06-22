<script setup>
import { ref, watch } from 'vue';
import { router, usePoll } from '@inertiajs/vue3';
import debounce from "@/lib/debounce";
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import EmailListItem from './components/EmailListItem.vue';

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

usePoll(1000)
</script>

<template>
  <div class="max-w-6xl mx-auto px-8 py-8">
    <div class="flex items-center justify-between mb-5">
      <h1 class="text-xl font-semibold tracking-tight">Triage queue</h1>

      <div class="flex items-center gap-2">
        <select
          :value="filters.sort || 'urgency'"
          class="px-2.5 py-1.5 text-sm border border-border rounded-md bg-surface"
          @change="applyFilter('sort', $event.target.value === 'urgency' ? undefined : $event.target.value)"
        >
          <option value="urgency">Sort: most urgent first</option>
          <option value="recent">Sort: most recent first</option>
        </select>

        <input
          v-model="searchTerm"
          type="search"
          placeholder="Search anonymized content…"
          class="w-64 px-3 py-1.5 text-sm border border-border rounded-md bg-surface focus:border-accent outline-none"
        />
      </div>
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
        <span class="w-24 text-right font-medium">Action</span>
      </div>

      <div v-if="emails.data.length === 0" class="px-5 py-14 text-center text-sm text-ink-faint">
        No emails match these filters.
      </div>

      <ul v-else class="divide-y divide-border">
        <EmailListItem
          v-for="email in emails.data"
          :key="email.id"
          :email="email"
        />
      </ul>
    </div>

    <Pagination :links="emails.links" />
  </div>
</template>
