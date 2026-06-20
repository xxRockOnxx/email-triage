<script setup>
const props = defineProps({
  label: { type: String, required: true },
  variant: { type: String, default: 'default' }, // default | danger
  confirmMessage: { type: String, default: null },
});

const emit = defineEmits(['click']);

function handleClick() {
  if (props.confirmMessage && !window.confirm(props.confirmMessage)) {
    return;
  }
  emit('click');
}
</script>

<template>
  <button
    type="button"
    class="px-3 py-1.5 rounded-md text-sm font-medium border transition-colors"
    :class="variant === 'danger'
      ? 'border-urgency-critical/30 text-urgency-critical hover:bg-urgency-critical-bg'
      : 'border-border text-ink-soft hover:bg-surface-sunken hover:text-ink'"
    @click="handleClick"
  >
    {{ label }}
  </button>
</template>
