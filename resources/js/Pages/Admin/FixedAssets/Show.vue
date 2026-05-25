<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">{{ asset.name }}</h1>
          <p class="text-sm text-gray-500 mt-0.5">{{ asset.code }} · {{ asset.category || 'Chưa phân nhóm' }}</p>
        </div>
        <div class="flex gap-2">
          <a :href="route('admin.fixed-assets.edit', asset.id)"
            class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50">
            Sửa
          </a>
          <a :href="route('admin.fixed-assets.index')"
            class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50">
            ← Danh sách
          </a>
        </div>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Nguyên giá</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(asset.acquisition_cost) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Hao mòn lũy kế</p>
          <p class="text-lg font-bold text-red-600">{{ fmt(asset.accumulated_depreciation) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Giá trị còn lại</p>
          <p class="text-lg font-bold text-green-700">{{ fmt(asset.net_book_value) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">KH/tháng</p>
          <p class="text-lg font-bold text-amber-700">{{ fmt(asset.monthly_depreciation) }}</p>
        </div>
      </div>

      <!-- Detail info -->
      <div class="bg-white rounded-xl border border-gray-200 p-5 grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
        <div>
          <p class="text-gray-500 text-xs mb-0.5">Ngày mua</p>
          <p class="font-medium text-gray-800">{{ asset.acquisition_date }}</p>
        </div>
        <div>
          <p class="text-gray-500 text-xs mb-0.5">Thời gian sử dụng</p>
          <p class="font-medium text-gray-800">{{ asset.useful_life_months }} tháng</p>
        </div>
        <div>
          <p class="text-gray-500 text-xs mb-0.5">Phương pháp KH</p>
          <p class="font-medium text-gray-800">Đường thẳng</p>
        </div>
        <div>
          <p class="text-gray-500 text-xs mb-0.5">Vị trí</p>
          <p class="font-medium text-gray-800">{{ asset.location || '—' }}</p>
        </div>
        <div>
          <p class="text-gray-500 text-xs mb-0.5">KH kỳ cuối</p>
          <p class="font-medium text-gray-800">{{ asset.last_depreciation_period || '—' }}</p>
        </div>
        <div>
          <p class="text-gray-500 text-xs mb-0.5">Trạng thái</p>
          <span class="px-2 py-0.5 rounded-full text-xs font-medium"
            :class="{
              'bg-green-100 text-green-800': asset.status === 'active',
              'bg-gray-100 text-gray-600': asset.status === 'fully_depreciated',
              'bg-red-100 text-red-600': asset.status === 'disposed',
            }">
            {{ statusLabel(asset.status) }}
          </span>
        </div>
        <div v-if="asset.notes" class="col-span-2 md:col-span-3">
          <p class="text-gray-500 text-xs mb-0.5">Ghi chú</p>
          <p class="text-gray-700">{{ asset.notes }}</p>
        </div>
      </div>

      <!-- Depreciation schedule -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
          <h2 class="font-semibold text-gray-800 text-sm">Lịch khấu hao</h2>
          <div class="flex items-center gap-3">
            <span class="text-xs text-gray-500">
              Đã ghi: {{ postedCount }} / {{ schedule.length }} kỳ
            </span>
            <span class="inline-flex items-center gap-1 text-xs">
              <span class="w-2.5 h-2.5 rounded-full bg-green-500 inline-block"></span> Đã ghi nhận
              <span class="w-2.5 h-2.5 rounded-full bg-gray-300 inline-block ml-2"></span> Dự kiến
            </span>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-2 font-semibold text-gray-600">Kỳ</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600">KH kỳ này</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600">Hao mòn LK trước</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600">Hao mòn LK sau</th>
                <th class="text-right px-4 py-2 font-semibold text-green-600">Giá trị còn lại</th>
                <th class="text-center px-4 py-2 font-semibold text-gray-600">Trạng thái</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="row in schedule" :key="row.period"
                :class="row.posted ? 'bg-white' : 'bg-gray-50 text-gray-400'">
                <td class="px-4 py-2 font-mono font-semibold">{{ row.period }}</td>
                <td class="px-4 py-2 text-right">{{ fmt(row.amount) }}</td>
                <td class="px-4 py-2 text-right">{{ fmt(row.accumulated_before) }}</td>
                <td class="px-4 py-2 text-right font-semibold text-red-600">{{ fmt(row.accumulated_after) }}</td>
                <td class="px-4 py-2 text-right font-semibold text-green-700">{{ fmt(row.net_book_value_after) }}</td>
                <td class="px-4 py-2 text-center">
                  <span v-if="row.posted" class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-medium">Đã ghi</span>
                  <span v-else class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">Dự kiến</span>
                </td>
              </tr>
              <tr v-if="schedule.length === 0">
                <td colspan="6" class="px-4 py-8 text-center text-gray-400">Chưa có lịch khấu hao</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  asset:    Object,
  schedule: Array,
});

const { formatVnd: fmt } = useCurrency();

const postedCount = computed(() => props.schedule.filter(r => r.posted).length);

function statusLabel(s) {
  return { active: 'Đang dùng', fully_depreciated: 'Đã KH hết', disposed: 'Đã thanh lý' }[s] ?? s;
}
</script>
