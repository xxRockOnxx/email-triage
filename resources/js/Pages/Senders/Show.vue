<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CategoryPill from '@/Components/CategoryPill.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  sender: { type: Object, required: true },
});

function entries(histogram) {
  return Object.entries(histogram ?? {}).sort((a, b) => b[1] - a[1]);
}
</script>

<template>
  <div class="max-w-2xl mx-auto px-8 py-8">
    <Link :href="route('senders.index')" class="text-sm text-ink-soft hover:text-accent">&larr; Back to senders</Link>

    <h1 class="text-xl font-semibold tracking-tight mt-3 mb-1">{{ sender.sender_email }}</h1>
    <p class="text-sm text-ink-soft mb-6 font-mono-tabular">
      {{ sender.email_count }} email{{ sender.email_count === 1 ? '' : 's' }} · {{ sender.sender_domain }}
    </p>

    <div class="grid grid-cols-2 gap-4 mb-6">
      <div class="bg-surface border border-border rounded-lg p-4">
        <h2 class="text-xs font-medium text-ink-soft uppercase tracking-wide mb-2">Category breakdown</h2>
        <ul class="space-y-1.5">
          <li v-for="[name, count] in entries(sender.category_histogram)" :key="name" class="flex items-center justify-between text-sm">
            <CategoryPill :name="name" />
            <span class="font-mono-tabular text-ink-soft">{{ count }}</span>
          </li>
        </ul>
      </div>

      <div class="bg-surface border border-border rounded-lg p-4">
        <h2 class="text-xs font-medium text-ink-soft uppercase tracking-wide mb-2">Action breakdown</h2>
        <ul class="space-y-1.5">
          <li v-for="[action, count] in entries(sender.action_histogram)" :key="action" class="flex items-center justify-between text-sm">
            <span class="text-ink-soft capitalize">{{ action.replace('_', ' ') }}</span>
            <span class="font-mono-tabular text-ink-soft">{{ count }}</span>
          </li>
        </ul>
      </div>
    </div>

    <div class="bg-surface border border-border rounded-lg p-4">
      <h2 class="text-xs font-medium text-ink-soft uppercase tracking-wide mb-3">Recent activity</h2>
      <ul class="space-y-2">
        <li
          v-for="(action, i) in sender.recent_actions ?? []"
          :key="i"
          class="flex items-center justify-between text-sm border-b border-border last:border-0 pb-2 last:pb-0"
        >
          <div class="flex items-center gap-2">
            <CategoryPill :name="action.category" />
            <span class="text-ink-soft capitalize">{{ action.action.replace('_', ' ') }}</span>
          </div>
          <span class="text-xs text-ink-faint font-mono-tabular">
            {{ new Date(action.at).toLocaleDateString() }}
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>
