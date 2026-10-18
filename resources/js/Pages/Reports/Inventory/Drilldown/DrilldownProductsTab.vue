<template>
  <div class="space-y-2">
    <p v-if="loading" class="text-sm text-gray-400">Đang tải...</p>
    <template v-else>
      <div class="overflow-x-auto border border-gray-200 rounded-lg max-h-[50vh] overflow-y-auto">
        <table class="min-w-full text-xs">
          <thead class="bg-gray-50 sticky top-0">
            <tr>
              <th class="text-left px-2 py-1.5">Mã SP</th>
              <th class="text-left px-2 py-1.5">Tên SP</th>
              <th class="text-left px-2 py-1.5">Danh mục</th>
              <th class="text-right px-2 py-1.5">SL</th>
              <th class="text-right px-2 py-1.5">Giá trị</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="row in rows" :key="row.id" class="hover:bg-gray-50">
              <td class="px-2 py-1.5 font-mono text-primary-700">{{ row.code }}</td>
              <td class="px-2 py-1.5">{{ row.name }}</td>
              <td class="px-2 py-1.5 text-gray-500">{{ row.category }}</td>
              <td class="px-2 py-1.5 text-right">{{ fmtQty(row.qty) }}</td>
              <td class="px-2 py-1.5 text-right font-medium">{{ formatVnd(row.value) }}</td>
            </tr>
            <tr v-if="!rows.length"><td colspan="5" class="text-center py-6 text-gray-400">Không có sản phẩm phát sinh trong phạm vi này</td></tr>
          </tbody>
        </table>
      </div>
      <div class="flex items-center justify-between text-xs text-gray-500">
        <span>Trang {{ meta.current_page }}/{{ meta.last_page }} · {{ meta.total }} sản phẩm (chỉ SP có phát sinh đúng KPI này)</span>
        <div class="flex gap-1">
          <button class="erp-btn-secondary erp-btn-sm" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">←</button>
          <button class="erp-btn-secondary erp-btn-sm" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">→</button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { formatVnd } from '@/composables/useCurrency';

const props = defineProps({ filters: Object, kpi: String });

const rows = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(false);

async function load(page = 1) {
  loading.value = true;
  try {
    const { data } = await axios.get(route('reports.inventory.drilldown'), {
      params: { ...props.filters, kpi: props.kpi, tab: 'products', page },
    });
    rows.value = data.data;
    meta.value = { current_page: data.current_page, last_page: data.last_page, total: data.total };
  } finally {
    loading.value = false;
  }
}

function fmtQty(n) { return Number(n ?? 0).toLocaleString('vi-VN'); }

onMounted(() => load(1));
</script>
