<template>
  <div v-if="tabs.length > 0"
    class="bg-white border-b border-gray-200 flex items-center overflow-x-auto flex-shrink-0">
    <div
      v-for="tab in tabs" :key="tab.url"
      :class="['group flex items-center gap-1.5 px-4 py-2 text-sm cursor-pointer border-b-2 whitespace-nowrap flex-shrink-0 transition-colors',
        isActive(tab.url)
          ? 'border-primary-500 text-primary-700 bg-primary-50 font-medium'
          : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50']"
    >
      <span @click="navigate(tab.url)">{{ tab.title }}</span>
      <button
        @click.stop="close(tab.url)"
        class="w-4 h-4 flex items-center justify-center rounded-full opacity-0 group-hover:opacity-100 hover:bg-red-100 hover:text-red-500 transition-all"
      >
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { useTabs } from '@/composables/useTabs';

const page = usePage();
const { tabs, closeTab } = useTabs();

function isActive(url) {
  const current = page.url.split('?')[0];
  return current === url || current.startsWith(url + '/');
}

function navigate(url) {
  router.visit(url);
}

function close(url) {
  closeTab(url, page.url);
}
</script>
