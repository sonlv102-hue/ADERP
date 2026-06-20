<template>
  <div class="min-h-screen bg-slate-100 flex">

    <!-- Sidebar -->
    <Sidebar :open="sidebarOpen" :is-mobile="isMobile" @close="sidebarOpen = false" />

    <!-- Mobile backdrop -->
    <div v-if="isMobile && sidebarOpen"
      class="fixed inset-0 bg-black/50 z-20"
      @click="sidebarOpen = false" />

    <!-- Main content -->
    <div class="flex-1 flex flex-col min-w-0">
      <TopBar @toggle-sidebar="toggleSidebar" />
      <TabBar />

      <!-- Flash messages -->
      <div v-if="flash.success || flash.error || flash.warning" class="px-6 pt-4">
        <div v-if="flash.success"
          class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg flex items-center gap-2">
          <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
          </svg>
          {{ flash.success }}
        </div>
        <div v-if="flash.error"
          class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg flex items-center gap-2">
          <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
          </svg>
          {{ flash.error }}
        </div>
        <div v-if="flash.warning"
          class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg flex items-center gap-2">
          <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
          </svg>
          {{ flash.warning }}
        </div>
      </div>

      <!-- Bút toán nháp chờ duyệt -->
      <div v-if="page.props.draftJournalEntryCount > 0" class="px-6 pt-4">
        <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg flex items-center justify-between gap-2 flex-wrap">
          <div class="flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
            <span>Đang có <strong>{{ page.props.draftJournalEntryCount }}</strong> bút toán nháp chờ duyệt — chưa lên sổ kế toán.</span>
          </div>
          <Link :href="route('accounting.journal-entries.index') + '?status=draft'"
                class="text-sm font-medium underline whitespace-nowrap hover:text-amber-900">
            Xem &amp; duyệt →
          </Link>
        </div>
      </div>

      <!-- Page content -->
      <main class="flex-1 p-4 lg:p-6">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Sidebar from '@/Components/Layout/Sidebar.vue';
import TopBar from '@/Components/Layout/TopBar.vue';
import TabBar from '@/Components/Layout/TabBar.vue';
import { useFlash } from '@/composables/useFlash';
import { useTabs } from '@/composables/useTabs';

const SIDEBAR_KEY = 'erp_sidebar_open';

const isMobile   = ref(false);
const sidebarOpen = ref(true); // corrected in onMounted
const { flash }  = useFlash();
const page       = usePage();
const { openTab } = useTabs();

function initSidebar() {
  isMobile.value   = window.innerWidth < 768;
  sidebarOpen.value = isMobile.value
    ? false
    : localStorage.getItem(SIDEBAR_KEY) !== 'false';
}

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value;
}

// Persist desktop state
watch(sidebarOpen, (val) => {
  if (!isMobile.value) localStorage.setItem(SIDEBAR_KEY, String(val));
});

// Debounced resize handler
let resizeTimer = null;
function handleResize() {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(() => {
    const wasMobile  = isMobile.value;
    isMobile.value   = window.innerWidth < 768;
    if (!wasMobile && isMobile.value) {
      sidebarOpen.value = false; // switched to mobile → close
    } else if (wasMobile && !isMobile.value) {
      sidebarOpen.value = localStorage.getItem(SIDEBAR_KEY) !== 'false'; // switched to desktop → restore
    }
  }, 100);
}

let removeNavigateListener = null;

onMounted(() => {
  initSidebar();
  window.addEventListener('resize', handleResize);
  openTab(page.url);
  removeNavigateListener = router.on('navigate', (event) => {
    openTab(event.detail.page.url);
    if (isMobile.value) sidebarOpen.value = false; // auto-close on mobile navigation
  });
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', handleResize);
  clearTimeout(resizeTimer);
  if (removeNavigateListener) removeNavigateListener();
});
</script>
