<template>
  <div class="relative" ref="dropdownRef">
    <!-- Bell button -->
    <button @click="toggle"
      class="relative p-1.5 text-gray-500 hover:text-gray-700 rounded-lg hover:bg-gray-100">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
      </svg>
      <!-- Unread badge -->
      <span v-if="unreadCount > 0"
        class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">
        {{ unreadCount > 99 ? '99+' : unreadCount }}
      </span>
    </button>

    <!-- Dropdown panel -->
    <div v-if="open"
      class="absolute right-0 top-full mt-2 w-80 bg-white rounded-xl shadow-lg border border-gray-100 z-50 overflow-hidden">
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Thông báo</span>
        <button v-if="unreadCount > 0" @click="markAllRead"
          class="text-xs text-primary-600 hover:text-primary-800 font-medium">
          Đọc tất cả
        </button>
      </div>

      <!-- Items -->
      <div class="max-h-80 overflow-y-auto">
        <div v-if="!items.length" class="py-8 text-center text-sm text-gray-500">
          Không có thông báo
        </div>
        <div v-for="n in items" :key="n.id"
          :class="['flex gap-3 px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-50 last:border-0',
            !n.read_at ? 'bg-primary-50/40' : '']"
          @click="handleClick(n)">
          <div :class="['mt-1.5 w-2 h-2 rounded-full flex-shrink-0', dotColor(n.color)]" />
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-gray-900 truncate">{{ n.title }}</p>
            <p class="text-xs text-gray-500 mt-0.5 line-clamp-2">{{ n.message }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ n.created_at }}</p>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="border-t border-gray-100">
        <Link :href="route('notifications.index')"
          class="block text-center py-2.5 text-sm text-primary-600 hover:bg-gray-50 font-medium">
          Xem tất cả thông báo
        </Link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const open = ref(false);
const unreadCount = ref(0);
const items = ref([]);
const dropdownRef = ref(null);

let pollInterval = null;

function toggle() {
  open.value = !open.value;
  if (open.value) fetchItems();
}

async function fetchUnreadCount() {
  try {
    const res = await fetch(route('notifications.unread-count'));
    const data = await res.json();
    unreadCount.value = data.count ?? 0;
  } catch {
    // silent fail
  }
}

async function fetchItems() {
  try {
    const res = await fetch(route('notifications.unread-count'));
    // Fetch latest notifications via a separate lightweight call
    // We reuse the index page data by navigating; for dropdown we fetch JSON
    const countData = await res.json();
    unreadCount.value = countData.count ?? 0;
  } catch {
    // silent fail
  }
}

function handleClick(n) {
  open.value = false;
  if (!n.read_at) {
    router.post(route('notifications.mark-read', n.id), {}, { preserveScroll: true });
  }
  if (n.url) {
    router.visit(n.url);
  }
}

function markAllRead() {
  router.post(route('notifications.mark-all-read'), {}, {
    preserveScroll: true,
    onSuccess: () => {
      unreadCount.value = 0;
      items.value = items.value.map(n => ({ ...n, read_at: new Date().toLocaleDateString('vi-VN') }));
    },
  });
}

function dotColor(color) {
  const map = { yellow: 'bg-yellow-400', blue: 'bg-blue-500', red: 'bg-red-500', green: 'bg-green-500' };
  return map[color] ?? 'bg-gray-400';
}

const handleClickOutside = (e) => {
  if (dropdownRef.value && !dropdownRef.value.contains(e.target)) open.value = false;
};

onMounted(() => {
  fetchUnreadCount();
  pollInterval = setInterval(fetchUnreadCount, 30000);
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  clearInterval(pollInterval);
  document.removeEventListener('click', handleClickOutside);
});
</script>
