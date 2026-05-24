<template>
  <AppLayout>
    <div class="space-y-5">
      <h1 class="text-2xl font-bold text-gray-900">Nhật ký hoạt động</h1>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="flex flex-wrap gap-3 items-end">
          <div class="flex-1 min-w-36">
            <label class="block text-xs font-medium text-gray-600 mb-1">Người dùng</label>
            <select v-model="filters.causer_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="">— Tất cả —</option>
              <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
          <div class="flex-1 min-w-36">
            <label class="block text-xs font-medium text-gray-600 mb-1">Đối tượng</label>
            <select v-model="filters.subject_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="">— Tất cả —</option>
              <option v-for="t in subjectTypes" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="flex-1 min-w-32">
            <label class="block text-xs font-medium text-gray-600 mb-1">Từ ngày</label>
            <input v-model="filters.date_from" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div class="flex-1 min-w-32">
            <label class="block text-xs font-medium text-gray-600 mb-1">Đến ngày</label>
            <input v-model="filters.date_to" type="date" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <button @click="search" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Tìm kiếm
          </button>
          <button @click="reset" class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm">
            Đặt lại
          </button>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600 w-36">Thời gian</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600 w-32">Người dùng</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Hành động</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600 w-40">Đối tượng</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600 w-56">Chi tiết</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="log in logs.data" :key="log.id" class="hover:bg-gray-50 align-top">
              <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ log.created_at }}</td>
              <td class="px-4 py-3 font-medium text-gray-800">{{ log.causer_name }}</td>
              <td class="px-4 py-3 text-gray-700">{{ log.description }}</td>
              <td class="px-4 py-3 text-gray-600">
                <span v-if="log.subject_type">
                  <span class="font-medium">{{ log.subject_type }}</span>
                  <span v-if="log.subject_id" class="text-gray-400"> #{{ log.subject_id }}</span>
                </span>
                <span v-else class="text-gray-400">—</span>
              </td>
              <td class="px-4 py-3">
                <template v-if="hasProperties(log.properties)">
                  <button @click="toggleExpand(log.id)" class="text-xs text-primary-600 hover:text-primary-800 mb-1">
                    {{ expanded.has(log.id) ? 'Thu gọn' : 'Xem chi tiết' }}
                  </button>
                  <pre v-if="expanded.has(log.id)" class="text-xs bg-gray-50 p-2 rounded max-h-24 overflow-auto">{{ formatProps(log.properties) }}</pre>
                </template>
                <span v-else class="text-gray-400">—</span>
              </td>
            </tr>
            <tr v-if="!logs.data?.length">
              <td colspan="5" class="px-4 py-10 text-center text-gray-400">Chưa có nhật ký nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="logs.links" :meta="logs.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

const props = defineProps({
  logs: Object,
  subjectTypes: Array,
  users: Array,
});

const filters = reactive({
  causer_id:    route().params?.causer_id ?? '',
  subject_type: route().params?.subject_type ?? '',
  date_from:    route().params?.date_from ?? '',
  date_to:      route().params?.date_to ?? '',
});

const expanded = ref(new Set());

const search = () => {
  router.get(route('admin.activity-logs.index'), filters, { preserveScroll: true, preserveState: true });
};

const reset = () => {
  filters.causer_id = '';
  filters.subject_type = '';
  filters.date_from = '';
  filters.date_to = '';
  router.get(route('admin.activity-logs.index'), {}, { preserveScroll: true });
};

const toggleExpand = (id) => {
  if (expanded.value.has(id)) {
    expanded.value.delete(id);
  } else {
    expanded.value.add(id);
  }
  // trigger reactivity
  expanded.value = new Set(expanded.value);
};

const hasProperties = (properties) => {
  if (!properties) return false;
  const obj = typeof properties === 'string' ? JSON.parse(properties) : properties;
  return obj && (obj.attributes || obj.old || Object.keys(obj).length > 0);
};

const formatProps = (properties) => {
  const obj = typeof properties === 'string' ? JSON.parse(properties) : properties;
  return JSON.stringify(obj, null, 2);
};
</script>
