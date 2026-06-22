<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { route } from 'ziggy-js';
import UrgencyBadge from '@/Components/UrgencyBadge.vue';
import CategoryPill from '@/Components/CategoryPill.vue';
import ConfidenceMeter from '@/Components/ConfidenceMeter.vue';
import ActionButton from '@/Components/ActionButton.vue';

const props = defineProps({
  email: { type: Object, required: true },
});

const formattedDate = computed(() => {
  return new Date(props.email.received_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
});


// Map a triage result's `suggested_action` to an inline list-row action.
// `reply` can't execute inline (the draft endpoint needs a body), so it
// routes to the detail page where the draft can be reviewed first.
const SUGGESTED_ACTIONS = {
  archive: { label: 'Archive', confirm: 'Archive this email?' },
  delete: { label: 'Delete', variant: 'danger', confirm: 'Move this email to trash?' },
  flag: { label: 'Flag', confirm: 'Flag this email?' },
  reply: { label: 'Reply', navigate: true },
} as const;

const suggestedAction = computed(() => {
  const type = props.email.latest_triage_result?.suggested_action;
  if (!type || type === 'none') return null;
  if (Object.hasOwn(SUGGESTED_ACTIONS, type)) return SUGGESTED_ACTIONS[type as keyof typeof SUGGESTED_ACTIONS];
  return null
});

function runAction() {
  if (!suggestedAction.value) return;

  const type = props.email.latest_triage_result.suggested_action;

  if (type === 'reply') {
    router.get(route('emails.show', props.email.id));
    return;
  }

  router.post(route(`emails.${type}`, props.email.id), {}, { preserveScroll: true });
}
</script>

<template>
  <li
    class="flex items-stretch gap-4 px-5 py-3.5 hover:bg-surface-sunken transition-colors"
  >
    <Link
      :href="route('emails.show', email.id)"
      class="flex items-stretch gap-4 flex-1 min-w-0"
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
      <p class="text-sm text-ink-soft">
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
          {{ formattedDate }}
        </span>
      </div>
    </Link>

    <div class="flex items-center justify-end shrink-0 w-24">
      <ActionButton
        v-if="suggestedAction"
        class="w-24"
        :label="suggestedAction.label"
        :variant="suggestedAction.variant || 'default'"
        :confirm-message="suggestedAction.confirm"
        @click="runAction"
      />
    </div>
  </li>
</template>
