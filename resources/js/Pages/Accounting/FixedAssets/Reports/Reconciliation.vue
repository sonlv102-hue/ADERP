<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Đối chiếu TK 211 / 214 với danh mục TSCĐ</h1>
          <p class="text-sm text-slate-500 mt-1">So sánh số dư sổ cái với tổng giá trị trên danh mục tài sản</p>
        </div>
        <input v-model="period" type="month" class="erp-input w-40" @change="applyFilters" />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <!-- TK 211 -->
        <div class="bg-white rounded-xl border" :class="Math.abs(diff211) < 1 ? 'border-green-200' : 'border-red-200'">
          <div class="p-5 border-b" :class="Math.abs(diff211) < 1 ? 'border-green-100 bg-green-50' : 'border-red-100 bg-red-50'">
            <h3 class="font-semibold" :class="Math.abs(diff211) < 1 ? 'text-green-800' : 'text-red-800'">
              TK 211 — Nguyên giá TSCĐ
            </h3>
          </div>
          <div class="p-5 space-y-3 text-sm">
            <div class="flex justify-between">
              <span class="text-slate-600">Danh mục TSCĐ (nguyên giá)</span>
              <span class="font-mono font-semibold">{{ fmt(catalog_original_cost) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-600">Số dư TK 211x (sổ cái)</span>
              <span class="font-mono font-semibold">{{ fmt(tk211_balance) }}</span>
            </div>
            <div class="flex justify-between border-t border-slate-100 pt-2 font-semibold">
              <span>Chênh lệch</span>
              <span :class="Math.abs(diff211) < 1 ? 'text-green-700' : 'text-red-700'">{{ fmt(diff211) }}</span>
            </div>
          </div>
        </div>

        <!-- TK 214 -->
        <div class="bg-white rounded-xl border" :class="Math.abs(diff214) < 1 ? 'border-green-200' : 'border-red-200'">
          <div class="p-5 border-b" :class="Math.abs(diff214) < 1 ? 'border-green-100 bg-green-50' : 'border-red-100 bg-red-50'">
            <h3 class="font-semibold" :class="Math.abs(diff214) < 1 ? 'text-green-800' : 'text-red-800'">
              TK 214 — Hao mòn lũy kế
            </h3>
          </div>
          <div class="p-5 space-y-3 text-sm">
            <div class="flex justify-between">
              <span class="text-slate-600">Danh mục TSCĐ (hao mòn LK)</span>
              <span class="font-mono font-semibold">{{ fmt(catalog_accum_dep) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-600">Số dư TK 214x (sổ cái)</span>
              <span class="font-mono font-semibold">{{ fmt(tk214_balance) }}</span>
            </div>
            <div class="flex justify-between border-t border-slate-100 pt-2 font-semibold">
              <span>Chênh lệch</span>
              <span :class="Math.abs(diff214) < 1 ? 'text-green-700' : 'text-red-700'">{{ fmt(diff214) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Unlinked JE lines -->
      <div v-if="unlinked_je_lines > 0" class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
        <strong>Cảnh báo:</strong> Có <strong>{{ unlinked_je_lines }}</strong> dòng bút toán hạch toán vào TK 211x/214x nhưng không gắn với TSCĐ cụ thể nào. Kiểm tra trong <Link :href="route('accounting.journal-entries.index')" class="underline">Phiếu kế toán</Link>.
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  period: String,
  catalog_original_cost: Number,
  catalog_accum_dep: Number,
  tk211_balance: Number,
  tk214_balance: Number,
  diff_211: Number,
  diff_214: Number,
  unlinked_je_lines: Number,
});

const { formatVnd } = useCurrency();
const fmt = (v) => formatVnd(v);

const period   = ref(props.period);
const diff211  = ref(props.diff_211);
const diff214  = ref(props.diff_214);

function applyFilters() {
  router.get(route('accounting.fixed-assets.reports.reconciliation'), { period: period.value }, { preserveState: true, replace: true });
}
</script>
