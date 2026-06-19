<template>
  <AppLayout>
    <div class="max-w-3xl space-y-5">
      <div class="flex items-center gap-3">
        <Link :href="route('catalog.price-lists.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ priceList.code }} — {{ priceList.name }}</h1>
        <StatusBadge v-if="priceList.is_default" color="green">Mặc định</StatusBadge>
      </div>

      <!-- Info card -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 grid grid-cols-2 gap-4 text-sm">
        <div>
          <p class="text-gray-500">Hiệu lực từ</p>
          <p class="font-medium text-gray-900">{{ priceList.valid_from ?? '—' }}</p>
        </div>
        <div>
          <p class="text-gray-500">Hiệu lực đến</p>
          <p class="font-medium text-gray-900">{{ priceList.valid_to ?? '—' }}</p>
        </div>
        <div>
          <p class="text-gray-500">Tạo bởi</p>
          <p class="font-medium text-gray-900">{{ priceList.creator }}</p>
        </div>
        <div v-if="priceList.notes">
          <p class="text-gray-500">Ghi chú</p>
          <p class="font-medium text-gray-900">{{ priceList.notes }}</p>
        </div>
      </div>

      <!-- Items table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Danh sách sản phẩm ({{ priceList.items.length }})</h2>
          <Link :href="route('catalog.price-lists.edit', priceList.id)"
            class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
        </div>

        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã SP</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên sản phẩm</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">ĐVT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="item in priceList.items" :key="item.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ item.product.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900">{{ item.product.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ item.product.unit }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(item.unit_price) }}</td>
            </tr>
            <tr v-if="!priceList.items.length">
              <td colspan="4" class="px-5 py-10 text-center text-gray-400">Chưa có sản phẩm nào</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

defineProps({ priceList: Object });

const { formatVnd } = useCurrency();
</script>
