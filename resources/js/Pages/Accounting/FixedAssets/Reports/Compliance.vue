<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Kiểm tra tuân thủ TSCĐ</h1>
          <p class="text-sm text-slate-500 mt-1">Phát hiện bất thường và vi phạm quy định kế toán</p>
        </div>
        <button @click="reload" class="erp-btn-secondary">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          Kiểm tra lại
        </button>
      </div>

      <div v-if="warnings.length === 0" class="bg-green-50 border border-green-200 rounded-xl p-8 text-center">
        <svg class="w-12 h-12 text-green-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-green-800 font-semibold text-lg">Không phát hiện vấn đề nào!</p>
        <p class="text-green-600 text-sm mt-1">Dữ liệu TSCĐ đang tuân thủ các quy định hiện hành.</p>
      </div>

      <div v-else class="space-y-3">
        <div v-for="(w, i) in warnings" :key="i"
          class="rounded-xl border p-4 flex items-start gap-3"
          :class="w.type === 'error' ? 'bg-red-50 border-red-200' : 'bg-amber-50 border-amber-200'">
          <svg class="w-5 h-5 flex-shrink-0 mt-0.5" :class="w.type === 'error' ? 'text-red-500' : 'text-amber-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path v-if="w.type === 'error'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <span :class="w.type === 'error' ? 'text-red-800' : 'text-amber-800'">{{ w.message }}</span>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

defineProps({ warnings: Array });

function reload() {
  router.get(route('accounting.fixed-assets.reports.compliance'), {}, { preserveState: false });
}
</script>
