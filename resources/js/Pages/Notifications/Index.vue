<template>
  <AppLayout title="Thông báo">
    <div class="max-w-3xl mx-auto py-6 px-4">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Thông báo</h1>
        <button v-if="notifications.data.length"
          @click="markAllRead"
          class="text-sm text-primary-600 hover:text-primary-800 font-medium">
          Đánh dấu tất cả đã đọc
        </button>
      </div>

      <!-- Empty state -->
      <div v-if="!notifications.data.length" class="text-center py-16 text-gray-500">
        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        <p>Không có thông báo nào</p>
      </div>

      <!-- Notification list -->
      <div v-else class="space-y-2">
        <div v-for="n in notifications.data" :key="n.id"
          :class="['bg-white rounded-xl border p-4 flex gap-4 cursor-pointer hover:shadow-sm transition-shadow',
            n.read_at ? 'border-gray-100' : 'border-primary-200 bg-primary-50/30']"
          @click="handleClick(n)">
          <!-- Color dot -->
          <div :class="['mt-1 w-2.5 h-2.5 rounded-full flex-shrink-0', dotColor(n.color)]" />

          <!-- Content -->
          <div class="flex-1 min-w-0">
            <div class="flex items-start justify-between gap-2">
              <p :class="['text-sm font-medium', n.read_at ? 'text-gray-700' : 'text-gray-900']">
                {{ n.title }}
              </p>
              <span class="text-xs text-gray-400 whitespace-nowrap flex-shrink-0">{{ n.created_at }}</span>
            </div>
            <p class="text-sm text-gray-600 mt-0.5 truncate">{{ n.message }}</p>
          </div>

          <!-- Unread badge -->
          <div v-if="!n.read_at" class="mt-1.5 w-2 h-2 rounded-full bg-primary-500 flex-shrink-0" />
        </div>
      </div>

      <!-- Pagination -->
      <Pagination :links="notifications.links" :meta="notifications" class="mt-6" />
    </div>
  </AppLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

const props = defineProps({
  notifications: { type: Object, required: true },
});

function dotColor(color) {
  const map = {
    yellow: 'bg-yellow-400',
    blue:   'bg-blue-500',
    red:    'bg-red-500',
    green:  'bg-green-500',
  };
  return map[color] ?? 'bg-gray-400';
}

function handleClick(n) {
  if (!n.read_at) {
    router.post(route('notifications.mark-read', n.id), {}, { preserveScroll: true });
  }
  if (n.url) {
    router.visit(n.url);
  }
}

function markAllRead() {
  router.post(route('notifications.mark-all-read'), {}, { preserveScroll: true });
}
</script>
