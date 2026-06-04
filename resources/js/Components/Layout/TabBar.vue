<template>
  <div v-if="tabs.length > 0"
    class="bg-white border-b border-gray-200 flex items-stretch flex-shrink-0 select-none"
    style="min-height: 36px;">

    <!-- Left scroll arrow -->
    <button v-if="canScrollLeft" @click="scrollLeft"
      class="flex-shrink-0 w-7 flex items-center justify-center border-r border-gray-200 hover:bg-gray-50 text-gray-400 hover:text-gray-700 transition-colors z-10">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
      </svg>
    </button>

    <!-- Tabs container -->
    <div ref="scrollEl" class="flex items-end overflow-x-auto flex-1 scroll-smooth"
      style="scrollbar-width:none; -ms-overflow-style:none;"
      @scroll="onScroll">
      <div v-for="tab in tabs" :key="tab.url"
        :title="tab.title"
        :class="['group relative flex items-center gap-1.5 px-3 py-2 text-xs cursor-pointer whitespace-nowrap flex-shrink-0 border-b-2 transition-all duration-150',
          'max-w-[160px]',
          isActive(tab.url)
            ? 'border-primary-500 text-primary-700 bg-primary-50/70 font-semibold'
            : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50 hover:border-gray-300']"
        @click="navigate(tab.url)"
        @mousedown.middle.prevent="close(tab.url)"
        @contextmenu.prevent="openCtxMenu($event, tab.url)"
      >
        <!-- Top accent line for active tab -->
        <span v-if="isActive(tab.url)"
          class="absolute top-0 left-0 right-0 h-0.5 bg-primary-500 rounded-b-sm" />

        <span class="truncate min-w-0 flex-1">{{ tab.title }}</span>

        <!-- Close button -->
        <button @click.stop="close(tab.url)"
          :class="['flex-shrink-0 w-3.5 h-3.5 flex items-center justify-center rounded-full transition-all duration-100',
            isActive(tab.url)
              ? 'opacity-50 hover:opacity-100 hover:bg-red-100 hover:text-red-500'
              : 'opacity-0 group-hover:opacity-50 hover:!opacity-100 hover:bg-red-100 hover:text-red-500']"
        >
          <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Right scroll arrow -->
    <button v-if="canScrollRight" @click="scrollRight"
      class="flex-shrink-0 w-7 flex items-center justify-center border-l border-gray-200 hover:bg-gray-50 text-gray-400 hover:text-gray-700 transition-colors z-10">
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
      </svg>
    </button>

    <!-- Context menu backdrop -->
    <div v-if="ctxMenu.visible" class="fixed inset-0 z-40"
      @click="ctxMenu.visible = false"
      @contextmenu.prevent="ctxMenu.visible = false" />

    <!-- Context menu -->
    <div v-if="ctxMenu.visible"
      class="fixed z-50 bg-white border border-gray-200 rounded-lg shadow-lg py-1 min-w-[168px] text-sm"
      :style="{ top: ctxMenu.y + 'px', left: ctxMenu.x + 'px' }">
      <button @click="ctxAction('close-this')"
        class="w-full text-left px-4 py-2 hover:bg-gray-50 text-gray-700 flex items-center gap-2">
        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
        Đóng tab này
      </button>
      <button @click="ctxAction('close-others')"
        :disabled="tabs.length <= 1"
        class="w-full text-left px-4 py-2 hover:bg-gray-50 text-gray-700 flex items-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed">
        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
        </svg>
        Đóng tab khác
      </button>
      <hr class="my-1 border-gray-100" />
      <button @click="ctxAction('close-all')"
        class="w-full text-left px-4 py-2 hover:bg-red-50 text-red-600 flex items-center gap-2">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
        </svg>
        Đóng tất cả
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useTabs } from '@/composables/useTabs';

const page = usePage();
const { tabs, closeTab, closeAllTabs, closeOtherTabs } = useTabs();

const scrollEl = ref(null);
const canScrollLeft = ref(false);
const canScrollRight = ref(false);
const ctxMenu = reactive({ visible: false, url: null, x: 0, y: 0 });

function normalizeUrl(url) {
  return url.split('?')[0].replace(/\/+$/, '');
}

function isActive(url) {
  const current = normalizeUrl(page.url);
  return current === url || current.startsWith(url + '/');
}

function navigate(url) {
  router.visit(url);
}

function close(url) {
  closeTab(url, page.url);
}

// Scroll controls
function scrollLeft() {
  scrollEl.value?.scrollBy({ left: -180, behavior: 'smooth' });
}

function scrollRight() {
  scrollEl.value?.scrollBy({ left: 180, behavior: 'smooth' });
}

function onScroll() {
  if (!scrollEl.value) return;
  const { scrollLeft, scrollWidth, clientWidth } = scrollEl.value;
  canScrollLeft.value = scrollLeft > 2;
  canScrollRight.value = scrollLeft + clientWidth < scrollWidth - 2;
}

function checkScroll() {
  nextTick(onScroll);
}

// Context menu
function openCtxMenu(e, url) {
  const x = Math.min(e.clientX, window.innerWidth - 190);
  const y = Math.min(e.clientY + 4, window.innerHeight - 130);
  ctxMenu.url = url;
  ctxMenu.x = x;
  ctxMenu.y = y;
  ctxMenu.visible = true;
}

function ctxAction(action) {
  const url = ctxMenu.url;
  ctxMenu.visible = false;
  if (action === 'close-this')   close(url);
  if (action === 'close-others') closeOtherTabs(url, page.url);
  if (action === 'close-all')    closeAllTabs(page.url);
}

// Keyboard: Ctrl+W closes active tab
function onKeydown(e) {
  if (e.ctrlKey && e.key === 'w' && tabs.value.length > 0) {
    const active = tabs.value.find(t => isActive(t.url));
    if (active) {
      e.preventDefault();
      close(active.url);
    }
  }
}

let resizeObs;
onMounted(() => {
  checkScroll();
  window.addEventListener('keydown', onKeydown);
  if (scrollEl.value) {
    resizeObs = new ResizeObserver(checkScroll);
    resizeObs.observe(scrollEl.value);
  }
});

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown);
  resizeObs?.disconnect();
});

watch(tabs, checkScroll, { deep: true });
</script>
