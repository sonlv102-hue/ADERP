<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-slate-900">Báo cáo tăng giảm TSCĐ</h1>
        <select v-model="year" @change="applyFilters" class="erp-input w-32">
          <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>

      <div class="grid grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase font-semibold">Đầu kỳ</p>
          <p class="text-xl font-bold text-slate-900 mt-1">{{ opening.count }} tài sản</p>
          <p class="text-sm text-slate-500 mt-0.5">{{ fmt(opening.cost) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase font-semibold text-green-700">+ Tăng trong kỳ</p>
          <p class="text-xl font-bold text-green-700 mt-1">{{ increase.count }} tài sản</p>
          <p class="text-sm text-green-600 mt-0.5">{{ fmt(increase.cost) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase font-semibold text-red-600">− Giảm trong kỳ</p>
          <p class="text-xl font-bold text-red-600 mt-1">{{ decrease.count }} tài sản</p>
          <p class="text-sm text-red-500 mt-0.5">{{ fmt(decrease.cost) }}</p>
        </div>
        <div class="bg-indigo-50 rounded-xl border border-indigo-200 p-5">
          <p class="text-xs text-indigo-600 uppercase font-semibold">Cuối kỳ</p>
          <p class="text-xl font-bold text-indigo-700 mt-1">{{ closing.count }} tài sản</p>
          <p class="text-sm text-indigo-600 mt-0.5">{{ fmt(closing.cost) }}</p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ year: Number, opening: Object, increase: Object, decrease: Object, closing: Object });
const { formatVnd } = useCurrency();
const fmt = (v) => formatVnd(v);

const year = ref(props.year);
const years = computed(() => Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - 2 + i));

function applyFilters() {
  router.get(route('accounting.fixed-assets.reports.movement'), { year: year.value }, { preserveState: true, replace: true });
}
</script>
