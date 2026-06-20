<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';

defineOptions({ layout: AppLayout });

defineProps({
  senders: { type: Object, required: true }, // paginator
});

function urgencyWeightLabel(score) {
  if (score === null || score === undefined) return '—';
  if (score >= 3.5) return 'Critical';
  if (score >= 2.5) return 'High';
  if (score >= 1.5) return 'Medium';
  return 'Low';
}
</script>

<template>
  <div class="max-w-4xl mx-auto px-8 py-8">
    <h1 class="text-xl font-semibold tracking-tight mb-1">Senders</h1>
    <p class="text-sm text-ink-soft mb-6">
      Rolling reputation built from triage history — used as a cheap signal alongside RAG context.
    </p>

    <div class="bg-surface border border-border rounded-lg overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs text-ink-faint uppercase tracking-wide">
            <th class="px-4 py-2.5 font-medium">Sender</th>
            <th class="px-4 py-2.5 font-medium text-right">Emails</th>
            <th class="px-4 py-2.5 font-medium">Typical urgency</th>
            <th class="px-4 py-2.5 font-medium text-right">Avg. confidence</th>
            <th class="px-4 py-2.5 font-medium">Last seen</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border">
          <tr v-for="sender in senders.data" :key="sender.id">
            <td class="px-4 py-2.5">
              <Link :href="route('senders.show', sender.id)" class="font-medium text-ink hover:text-accent">
                {{ sender.sender_email }}
              </Link>
              <div class="text-xs text-ink-faint">{{ sender.sender_domain }}</div>
            </td>
            <td class="px-4 py-2.5 text-right font-mono-tabular">{{ sender.email_count }}</td>
            <td class="px-4 py-2.5 text-ink-soft">{{ urgencyWeightLabel(sender.avg_urgency_score) }}</td>
            <td class="px-4 py-2.5 text-right font-mono-tabular">
              {{ sender.avg_confidence ? Math.round(sender.avg_confidence) + '%' : '—' }}
            </td>
            <td class="px-4 py-2.5 text-ink-faint font-mono-tabular text-xs">
              {{ sender.last_seen_at ? new Date(sender.last_seen_at).toLocaleDateString() : '—' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Pagination :links="senders.links" />
  </div>
</template>
