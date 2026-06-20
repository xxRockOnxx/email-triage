<script setup>
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const nav = [
  { label: 'Inbox', href: route('dashboard'), routeName: 'dashboard' },
  { label: 'Triage queue', href: route('emails.index'), routeName: 'emails.index' },
  { label: 'Categories', href: route('categories.index'), routeName: 'categories.index' },
  { label: 'Senders', href: route('senders.index'), routeName: 'senders.index' },
  { label: 'Settings', href: route('settings.index'), routeName: 'settings.index' },
];

function isActive(routeName) {
  return route().current(routeName);
}
</script>

<template>
  <div class="min-h-screen bg-bg text-ink flex">
    <aside class="w-56 shrink-0 border-r border-border bg-surface flex flex-col">
      <div class="px-5 py-5 border-b border-border">
        <div class="font-semibold tracking-tight text-[15px]">Triage</div>
        <div class="text-xs text-ink-faint font-mono-tabular mt-0.5">local · private</div>
      </div>

      <nav class="flex-1 px-2 py-4 space-y-0.5">
        <Link
          v-for="item in nav"
          :key="item.routeName"
          :href="item.href"
          class="block px-3 py-2 rounded-md text-sm transition-colors"
          :class="isActive(item.routeName)
            ? 'bg-accent-soft text-accent font-medium'
            : 'text-ink-soft hover:bg-surface-sunken hover:text-ink'"
        >
          {{ item.label }}
        </Link>
      </nav>

      <div class="px-5 py-4 border-t border-border text-xs text-ink-faint">
        Anonymized before any LLM call.
      </div>
    </aside>

    <div class="flex-1 min-w-0 flex flex-col">
      <div
        v-if="page.props.flash?.success"
        class="bg-accent-soft text-accent text-sm px-6 py-2.5 border-b border-border"
      >
        {{ page.props.flash.success }}
      </div>

      <main class="flex-1 min-w-0">
        <slot />
      </main>
    </div>
  </div>
</template>
