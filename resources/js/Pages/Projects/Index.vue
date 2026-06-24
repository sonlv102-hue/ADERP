<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Dự án thi công IT</h1>
        <div class="flex gap-2 flex-wrap">
          <ExportExcelButton :endpoint="route('projects.projects.export-excel')" />
          <Link v-if="can('projects.create')" :href="route('projects.projects.create')" class="erp-btn-primary flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Tạo dự án
          </Link>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã DA</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên dự án</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người phụ trách</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Bắt đầu</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Dự kiến HT</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tiến độ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="p in projects.data" :key="p.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ p.code }}</td>
              <td class="px-5 py-3 text-gray-800 font-medium">{{ p.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ p.customer }}</td>
              <td class="px-5 py-3 text-gray-600">{{ p.manager ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ p.start_date ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ p.expected_end_date ?? '—' }}</td>
              <td class="px-5 py-3">
                <div class="flex items-center gap-2">
                  <div class="w-20 bg-gray-200 rounded-full h-2">
                    <div class="bg-primary-500 h-2 rounded-full" :style="{ width: p.progress + '%' }" />
                  </div>
                  <span class="text-xs text-gray-500">{{ p.progress }}%</span>
                </div>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="p.status_color">{{ p.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('projects.projects.show', p.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!projects.data?.length">
              <td colspan="9" class="px-5 py-10 text-center text-gray-400">Chưa có dự án nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="projects.links" :meta="projects.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import ExportExcelButton from '@/Components/Shared/ExportExcelButton.vue';
import { usePermission } from '@/composables/usePermission';

defineProps({ projects: Object });

const { hasPermission } = usePermission();
const can = hasPermission;
</script>
