<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionButton from '@/Components/ActionButton.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  categories: { type: Array, required: true },
  pendingReview: { type: Array, required: true },
});

const mergeTargetByCategory = ref({});

const newCategoryForm = useForm({
  name: '',
  description: '',
  confidence_threshold: null,
});

function createCategory() {
  newCategoryForm.post(route('categories.store'), {
    onSuccess: () => newCategoryForm.reset(),
  });
}

function accept(category) {
  router.post(route('categories.accept', category.id));
}

function reject(category) {
  router.post(route('categories.reject', category.id));
}

function merge(category) {
  const targetId = mergeTargetByCategory.value[category.id];
  if (!targetId) return;
  router.post(route('categories.merge', category.id), { merge_into_id: targetId });
}

function destroy(category) {
  router.delete(route('categories.destroy', category.id));
}

const sourceLabel = { gmail: 'Gmail', llm: 'LLM', user: 'You' };
</script>

<template>
  <div class="max-w-4xl mx-auto px-8 py-8">
    <h1 class="text-xl font-semibold tracking-tight mb-1">Categories</h1>
    <p class="text-sm text-ink-soft mb-8">
      New categories proposed by the triage model land here for review before they're used for auto-filing.
    </p>

    <!-- Pending review -->
    <section v-if="pendingReview.length" class="mb-8">
      <h2 class="text-sm font-semibold mb-3">Pending review ({{ pendingReview.length }})</h2>
      <ul class="space-y-2">
        <li
          v-for="category in pendingReview"
          :key="category.id"
          class="bg-surface border border-dashed border-ink-faint rounded-lg p-4"
        >
          <div class="flex items-start justify-between gap-4 mb-3">
            <div>
              <div class="font-medium text-sm text-ink">{{ category.name }}</div>
              <p class="text-sm text-ink-soft mt-0.5">{{ category.description }}</p>
              <p class="text-xs text-ink-faint mt-1 font-mono-tabular">
                {{ category.triage_results_count }} email{{ category.triage_results_count === 1 ? '' : 's' }} proposed this
              </p>
            </div>
          </div>

          <div class="flex items-center gap-2 flex-wrap">
            <ActionButton label="Accept" @click="accept(category)" />
            <ActionButton label="Reject" variant="danger" @click="reject(category)" />

            <div class="flex items-center gap-1.5 ml-2">
              <select
                v-model="mergeTargetByCategory[category.id]"
                class="text-sm border border-border rounded-md px-2 py-1 bg-surface"
              >
                <option :value="null" disabled selected>Merge into…</option>
                <option
                  v-for="c in categories.filter(c => c.status === 'active')"
                  :key="c.id"
                  :value="c.id"
                >
                  {{ c.name }}
                </option>
              </select>
              <ActionButton label="Merge" @click="merge(category)" />
            </div>
          </div>
        </li>
      </ul>
    </section>

    <!-- Active categories -->
    <section class="mb-8">
      <h2 class="text-sm font-semibold mb-3">Active categories</h2>
      <div class="bg-surface border border-border rounded-lg overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-left text-xs text-ink-faint uppercase tracking-wide">
              <th class="px-4 py-2.5 font-medium">Name</th>
              <th class="px-4 py-2.5 font-medium">Source</th>
              <th class="px-4 py-2.5 font-medium text-right">Emails</th>
              <th class="px-4 py-2.5 font-medium text-right">Threshold</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="category in categories.filter(c => c.status === 'active')" :key="category.id">
              <td class="px-4 py-2.5">
                <div class="font-medium text-ink">{{ category.name }}</div>
                <div class="text-xs text-ink-soft">{{ category.description }}</div>
              </td>
              <td class="px-4 py-2.5 text-ink-soft">{{ sourceLabel[category.source] }}</td>
              <td class="px-4 py-2.5 text-right font-mono-tabular">{{ category.triage_results_count }}</td>
              <td class="px-4 py-2.5 text-right font-mono-tabular text-ink-soft">
                {{ category.confidence_threshold ?? '—' }}
              </td>
              <td class="px-4 py-2.5 text-right">
                <button
                  v-if="!category.is_system_default"
                  class="text-xs text-ink-faint hover:text-urgency-critical"
                  @click="destroy(category)"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Create new -->
    <section>
      <h2 class="text-sm font-semibold mb-3">Add a category</h2>
      <form class="bg-surface border border-border rounded-lg p-4 flex items-end gap-3" @submit.prevent="createCategory">
        <label class="flex-1">
          <span class="text-xs text-ink-soft mb-1 block">Name</span>
          <input v-model="newCategoryForm.name" type="text" class="w-full px-2.5 py-1.5 text-sm border border-border rounded-md bg-surface" />
        </label>
        <label class="flex-[2]">
          <span class="text-xs text-ink-soft mb-1 block">Description</span>
          <input v-model="newCategoryForm.description" type="text" class="w-full px-2.5 py-1.5 text-sm border border-border rounded-md bg-surface" />
        </label>
        <ActionButton label="Add" @click="createCategory" />
      </form>
    </section>
  </div>
</template>
