<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import DOMPurify from 'dompurify';
import AppLayout from '@/Layouts/AppLayout.vue';
import UrgencyBadge from '@/Components/UrgencyBadge.vue';
import CategoryPill from '@/Components/CategoryPill.vue';
import ConfidenceMeter from '@/Components/ConfidenceMeter.vue';
import ActionButton from '@/Components/ActionButton.vue';
import ProcessingLog from '@/Components/ProcessingLog.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
  email: { type: Object, required: true },
  deanonymized_summary: { type: String, default: null },
  categories: { type: Array, default: () => [] },
});

const triage = computed(() => props.email.latest_triage_result);

const sanitizedBody = computed(() => {
    // Prefer the HTML body for rich rendering; fall back to the plain-text
    // body for text-only emails and pre-existing rows without body_html_enc.
    if (props.email.body_html_enc) {
        return DOMPurify.sanitize(props.email.body_html_enc, {
            FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form', 'base'],
            FORBID_ATTR: ['onerror', 'onload', 'onclick'], // belt-and-suspenders; DOMPurify strips these by default anyway
            ADD_ATTR: ['target'], // so links can open in a new tab
        });
    }
    // Plain-text fallback: escape so <, >, & render literally (never as markup).
    const escaped = (props.email.body_enc ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    return `<pre style="white-space:pre-wrap;margin:0;font-family:inherit;">${escaped}</pre>`;
});

// Auto-size the rendered message iframe to its content. The sandbox keeps
// `allow-same-origin` (no scripts), so the parent can read the iframe's
// contentDocument to measure height; we re-measure on load and whenever
// late-loading images/fonts change the content height.
const bodyFrame = ref(null);
let bodyObserver = null;

function applyBodyHeight(doc) {
  if (!doc || !doc.documentElement) return;
  const height = Math.max(doc.documentElement.scrollHeight, doc.body?.scrollHeight ?? 0);
  if (bodyFrame.value) bodyFrame.value.style.height = `${height}px`;
}

function autoresizeBody() {
  const frame = bodyFrame.value;
  if (!frame) return;
  const doc = frame.contentDocument;
  if (!doc) return;
  applyBodyHeight(doc);

  if (bodyObserver) bodyObserver.disconnect();
  bodyObserver = new ResizeObserver(() => applyBodyHeight(frame.contentDocument));
  bodyObserver.observe(doc.documentElement);
}

onBeforeUnmount(() => bodyObserver?.disconnect());

const showCorrectionPanel = ref(false);
const showReplyDraft = ref(false);

const correctionForm = useForm({
  category_id: triage.value?.category_id ?? null,
  urgency: triage.value?.urgency ?? 'low',
  suggested_action: triage.value?.suggested_action ?? 'none',
});

const replyForm = useForm({
  body: triage.value?.suggested_reply_draft ?? '',
});

function approve() {
  router.post(route('triage-results.approve', triage.value.id));
}

function submitCorrection() {
  correctionForm.post(route('triage-results.correct', triage.value.id), {
    onSuccess: () => { showCorrectionPanel.value = false; },
  });
}

function archive() {
  router.post(route('emails.archive', props.email.id));
}

function deleteEmail() {
  router.post(route('emails.delete', props.email.id));
}

function flag() {
  router.post(route('emails.flag', props.email.id));
}

function submitReplyDraft() {
  replyForm.post(route('emails.reply-draft', props.email.id), {
    onSuccess: () => { showReplyDraft.value = false; },
  });
}

function undo(logId) {
    router.post(route('action-logs.undo', logId))
}

function formatDateTime(iso) {
  return new Date(iso).toLocaleString(undefined, {
    month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
  });
}

const STATUS_LABEL = {
  needs_review: 'Needs review',
  auto_filed: 'Auto-filed',
  corrected: 'Corrected',
};
</script>

<template>
  <div class="max-w-3xl mx-auto px-8 py-8">
    <Link :href="route('emails.index')" class="text-sm text-ink-soft hover:text-accent">&larr; Back to queue</Link>

    <div class="mt-4 mb-6">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <h1 class="text-lg font-semibold text-ink truncate">
            {{ email.anonymized_subject || '(no subject)' }}
          </h1>
          <p class="text-sm text-ink-soft mt-1">
            From <span class="font-medium text-ink">{{ email.sender_name || email.sender_email }}</span>
            <span class="text-ink-faint">· {{ email.sender_email }}</span>
            <span class="mx-1.5 text-ink-faint">·</span>
            <span class="font-mono-tabular">{{ formatDateTime(email.received_at) }}</span>
          </p>
        </div>
        <UrgencyBadge v-if="triage" :urgency="triage.urgency" />
      </div>
    </div>

    <!-- Triage summary card -->
    <div v-if="triage" class="bg-surface border border-border rounded-lg p-5 mb-4">
      <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
          <CategoryPill v-if="triage.category" :name="triage.category.name" />
          <CategoryPill v-else-if="triage.proposed_category_name" :name="triage.proposed_category_name" pending />
          <span class="text-xs text-ink-faint font-mono-tabular uppercase tracking-wide">
            {{ STATUS_LABEL[triage.status] }}
          </span>
        </div>
        <ConfidenceMeter :confidence="triage.confidence" />
      </div>

      <p class="text-sm text-ink leading-relaxed mb-4">
        {{ deanonymized_summary || triage.summary }}
      </p>

      <div
        v-if="triage.triage_reasoning && triage.status !== 'corrected'"
        class="text-xs text-ink-soft bg-surface-sunken rounded px-3 py-2 mb-4"
      >
        <span class="font-medium">Why this triage:</span> {{ triage.triage_reasoning }}
      </div>

      <div v-if="triage.proposed_category_reasoning" class="text-xs text-ink-soft bg-surface-sunken rounded px-3 py-2 mb-4">
        <span class="font-medium">Why a new category:</span> {{ triage.proposed_category_reasoning }}
      </div>

      <div class="text-xs text-ink-faint font-mono-tabular mb-4">
        {{ triage.llm_backend }}/{{ triage.llm_model }} · suggested action: {{ triage.suggested_action }}
      </div>

      <!-- Review actions -->
      <div class="pt-3 border-t border-border flex items-center gap-2">
        <ActionButton
          v-if="triage.status === 'needs_review'"
          label="Approve as-is"
          @click="approve"
        />
        <ActionButton
          label="Correct…"
          @click="showCorrectionPanel = !showCorrectionPanel"
        />
      </div>

      <!-- Correction panel -->
      <div v-if="showCorrectionPanel" class="mt-4 pt-4 border-t border-border space-y-3">
        <label class="block">
          <span class="text-xs text-ink-soft mb-1 block">Category</span>
          <select
            v-model="correctionForm.category_id"
            class="w-full px-2.5 py-1.5 text-sm border border-border rounded-md bg-surface"
          >
            <option :value="null">— No category —</option>
            <option v-for="category in categories" :key="category.id" :value="category.id">
              {{ category.name }}
            </option>
          </select>
        </label>

        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="text-xs text-ink-soft mb-1 block">Urgency</span>
            <select
              v-model="correctionForm.urgency"
              class="w-full px-2.5 py-1.5 text-sm border border-border rounded-md bg-surface"
            >
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </label>

          <label class="block">
            <span class="text-xs text-ink-soft mb-1 block">Suggested action</span>
            <select
              v-model="correctionForm.suggested_action"
              class="w-full px-2.5 py-1.5 text-sm border border-border rounded-md bg-surface"
            >
              <option value="none">None</option>
              <option value="reply">Reply</option>
              <option value="archive">Archive</option>
              <option value="delete">Delete</option>
              <option value="flag">Flag</option>
            </select>
          </label>
        </div>

        <div class="flex items-center gap-2">
          <ActionButton label="Save correction" @click="submitCorrection" />
          <button class="text-xs text-ink-faint hover:text-ink" @click="showCorrectionPanel = false">Cancel</button>
        </div>
      </div>
    </div>

    <div v-else class="bg-surface-sunken border border-border rounded-lg p-5 mb-4 text-sm text-ink-faint">
      Triage in progress — check back shortly.
    </div>

    <!-- Email actions -->
    <div class="flex items-center gap-2 mb-6">
      <ActionButton label="Archive" @click="archive" />
      <ActionButton label="Flag" @click="flag" />
      <ActionButton label="Draft reply" @click="showReplyDraft = !showReplyDraft" />
      <ActionButton label="Delete" variant="danger" confirm-message="Move this email to trash?" @click="deleteEmail" />
    </div>

    <div v-if="showReplyDraft" class="bg-surface border border-border rounded-lg p-5 mb-6">
      <span class="text-xs text-ink-soft mb-2 block">
        This creates a draft in Gmail — it will not be sent automatically.
      </span>
      <textarea
        v-model="replyForm.body"
        rows="6"
        class="w-full px-3 py-2 text-sm border border-border rounded-md bg-surface resize-y mb-3"
      />
      <ActionButton label="Save as Gmail draft" @click="submitReplyDraft" />
    </div>

    <!-- Body -->
    <div class="bg-surface border border-border rounded-lg p-5">
      <h2 class="text-xs font-medium text-ink-soft uppercase tracking-wide mb-3">Message</h2>
       <iframe
            ref="bodyFrame"
            class="w-full"
            :srcdoc="sanitizedBody"
            sandbox="allow-same-origin"
            @load="autoresizeBody"
       />
    </div>

    <!-- Action history -->
    <div v-if="email.actions_log?.length" class="mt-6">
      <h2 class="text-xs font-medium text-ink-soft uppercase tracking-wide mb-2">Activity</h2>
      <ul class="relative border-l border-ink-faint/20 ml-2 pl-4 space-y-4">
        <li
          v-for="log in email.actions_log"
          :key="log.id"
          class="relative flex items-center justify-between gap-4 text-xs group"
        >
          <div
            class="absolute -left-[21px] w-2.5 h-2.5 rounded-full border border-background bg-ink-faint"
            :class="{ 'bg-accent': log.initiated_by === 'auto', 'line-through opacity-50': log.undone_at }"
          />

          <div class="flex items-center gap-3 min-w-0">
            <span class="font-mono text-ink-faint whitespace-nowrap">
              {{ formatDateTime(log.executed_at) }}
            </span>

            <div class="flex items-center gap-1.5 min-w-0">
              <span
                class="font-medium text-ink-base truncate"
                :class="{ 'line-through text-ink-faint': log.undone_at }"
              >
                {{ log.action_type }}
              </span>

              <span
                v-if="log.initiated_by === 'auto'"
                class="px-1.5 py-0.5 rounded text-[10px] bg-accent/10 text-accent font-medium uppercase tracking-wider"
              >
                Auto
              </span>
            </div>
          </div>

          <div class="flex items-center gap-3 shrink-0 text-right">
            <div v-if="log.action_type === 'delete' && !log.undone_at">
              <ActionButton
                label="Undo"
                class="hover:text-danger transition-colors"
                @click="undo(log.id)"
              />
            </div>

            <div v-if="log.undone_at" class="text-ink-faint flex flex-col items-end">
              <span class="text-[10px] uppercase tracking-wide text-ink-soft/70">Undone</span>
              <span class="font-mono text-[11px]">{{ formatDateTime(log.undone_at) }}</span>
            </div>
          </div>
        </li>
      </ul>
    </div>

    <!-- Processing log (pipeline internals: what was configured/sent/received) -->
    <ProcessingLog v-if="email.pipeline_logs?.length" :logs="email.pipeline_logs" />
  </div>
</template>
