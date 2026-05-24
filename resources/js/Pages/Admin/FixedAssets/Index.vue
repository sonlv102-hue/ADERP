<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Quản lý Tài sản cố định</h1>
        <a :href="route('admin.fixed-assets.create')" class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          + Thêm TSCĐ
        </a>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="border-b border-gray-200 bg-gray-50">
            <tr>
              <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Mã</th>
              <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Tên tài sản</th>
              <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Nhóm</th>
              <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Ngày mua</th>
              <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Nguyên giá</th>
              <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Hao mòn LK</th>
              <th class="text-right px-4 py-2 font-semibold text-green-600 text-xs">Còn lại</th>
              <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Trạng thái</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="fa in assets" :key="fa.id" class="hover:bg-gray-50">
              <td class="px-4 py-2 font-mono font-semibold text-gray-800 text-xs">{{ fa.code }}</td>
              <td class="px-4 py-2 text-gray-800 font-medium text-xs">{{ fa.name }}</td>
              <td class="px-4 py-2 text-gray-500 text-xs">{{ fa.category || '—' }}</td>
              <td class="px-4 py-2 text-gray-600 text-xs">{{ fa.acquisition_date }}</td>
              <td class="px-4 py-2 text-right text-gray-800 text-xs">{{ fmt(fa.acquisition_cost) }}</td>
              <td class="px-4 py-2 text-right text-red-700 text-xs">{{ fmt(fa.accumulated_depreciation) }}</td>
              <td class="px-4 py-2 text-right font-semibold text-green-700 text-xs">{{ fmt(fa.net_book_value) }}</td>
              <td class="px-4 py-2 text-xs">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="{
                    'bg-green-100 text-green-800': fa.status === 'active',
                    'bg-gray-100 text-gray-600': fa.status === 'fully_depreciated',
                    'bg-red-100 text-red-600': fa.status === 'disposed',
                  }">
                  {{ statusLabel(fa.status) }}
                </span>
              </td>
              <td class="px-4 py-2 text-right text-xs">
                <a :href="route('admin.fixed-assets.edit', fa.id)" class="text-primary-600 hover:underline mr-3">Sửa</a>
                <button @click="destroy(fa)" class="text-red-600 hover:underline">Xóa</button>
              </td>
            </tr>
            <tr v-if="assets.length === 0">
              <td colspan="9" class="px-4 py-8 text-center text-gray-400 text-sm">Chưa có tài sản cố định</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

defineProps({ assets: Array });

const { formatVnd: fmt } = useCurrency();

function statusLabel(s) {
  return { active: 'Đang dùng', fully_depreciated: 'Đã KH hết', disposed: 'Đã thanh lý' }[s] ?? s;
}

function destroy(fa) {
  if (!confirm(`Xóa tài sản "${fa.name}"?`)) return;
  router.delete(route('admin.fixed-assets.destroy', fa.id));
}
</script>
