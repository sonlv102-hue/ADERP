<template>
  <AppLayout>
    <div class="max-w-5xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('accounting.fixed-assets.index')" class="text-slate-400 hover:text-slate-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Tính khấu hao TSCĐ</h1>
          <p class="text-sm text-slate-500 mt-0.5">Bước 1: Xem trước — Bước 2: Tạo bút toán nháp — Bước 3: Ghi sổ</p>
        </div>
      </div>

      <!-- Chọn kỳ -->
      <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="flex items-end gap-4">
          <div>
            <label class="erp-label">Kỳ khấu hao (YYYY-MM) <span class="text-red-500">*</span></label>
            <input v-model="period" type="month" class="erp-input w-48" />
          </div>
          <button @click="loadPreview" :disabled="loading" class="erp-btn-secondary">
            <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            Xem trước
          </button>
          <button v-if="can('accounting.manage') && preview.length > 0" @click="confirmRun" class="erp-btn-primary">
            Tạo bút toán nháp
          </button>
        </div>
      </div>

      <!-- Preview table -->
      <div v-if="preview.length > 0" class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex justify-between items-center">
          <h3 class="font-semibold text-slate-800">
            Xem trước khấu hao kỳ {{ period }}
          </h3>
          <div class="text-sm text-slate-600">
            <strong>{{ preview.length }}</strong> tài sản ·
            Tổng KH: <strong class="text-indigo-700">{{ fmt(totalAmount) }}</strong>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
              <tr>
                <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase">Mã</th>
                <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase">Tên TSCĐ</th>
                <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase">Bộ phận</th>
                <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase">TK CP</th>
                <th class="text-left px-4 py-3 font-semibold text-slate-500 text-xs uppercase">TK HM</th>
                <th class="text-right px-4 py-3 font-semibold text-slate-500 text-xs uppercase">KH tháng</th>
                <th class="text-right px-4 py-3 font-semibold text-slate-500 text-xs uppercase">HM LK trước</th>
                <th class="text-right px-4 py-3 font-semibold text-slate-500 text-xs uppercase">Còn lại</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in preview" :key="row.asset_id">
                <td class="px-4 py-2 font-mono text-xs text-slate-600">{{ row.asset_code }}</td>
                <td class="px-4 py-2 text-slate-900 font-medium">{{ row.asset_name }}</td>
                <td class="px-4 py-2 text-slate-500 text-xs">{{ row.department || '—' }}</td>
                <td class="px-4 py-2 font-mono text-xs text-indigo-700">{{ row.expense_account }}</td>
                <td class="px-4 py-2 font-mono text-xs text-indigo-700">{{ row.dep_account }}</td>
                <td class="px-4 py-2 text-right font-mono font-semibold text-slate-900">{{ fmt(row.amount) }}</td>
                <td class="px-4 py-2 text-right font-mono text-slate-500">{{ fmt(row.accumulated_before) }}</td>
                <td class="px-4 py-2 text-right font-mono font-semibold text-indigo-700">{{ fmt(row.net_book_value) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-slate-50 border-t border-slate-200">
              <tr>
                <td colspan="5" class="px-4 py-3 font-semibold text-slate-700">Tổng cộng ({{ preview.length }} tài sản)</td>
                <td class="px-4 py-3 text-right font-mono font-bold text-slate-900">{{ fmt(totalAmount) }}</td>
                <td colspan="2" />
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <div v-if="preview.length === 0 && previewed" class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center text-amber-700">
        Không có tài sản nào cần khấu hao trong kỳ {{ period }}.
      </div>
    </div>

    <!-- Confirm run -->
    <Modal :show="showConfirm" @close="showConfirm = false" title="Xác nhận tạo bút toán khấu hao">
      <p class="text-slate-700">
        Tạo bút toán khấu hao nháp cho <strong>{{ preview.length }}</strong> tài sản, kỳ <strong>{{ period }}</strong>?
      </p>
      <p class="text-sm text-slate-500 mt-2">Bút toán sẽ ở trạng thái <strong>Nháp</strong> — bạn cần kiểm tra và ghi sổ trong Phiếu kế toán.</p>
      <div class="flex justify-end gap-2 mt-4">
        <button @click="showConfirm = false" class="erp-btn-secondary">Hủy</button>
        <button @click="doRun" class="erp-btn-primary">Tạo bút toán nháp</button>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Modal from '@/Components/Shared/Modal.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ defaultPeriod: String });

const { can } = usePermission();
const { formatVnd } = useCurrency();
const fmt = (v) => formatVnd(v);

const period      = ref(props.defaultPeriod);
const preview     = ref([]);
const previewed   = ref(false);
const loading     = ref(false);
const showConfirm = ref(false);

const totalAmount = computed(() => preview.value.reduce((s, r) => s + r.amount, 0));

async function loadPreview() {
  loading.value = true;
  previewed.value = false;
  try {
    const res = await fetch(route('accounting.fixed-assets.depreciation.preview') + '?period=' + period.value, {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const data = await res.json();
    preview.value = data.rows || [];
    previewed.value = true;
  } finally {
    loading.value = false;
  }
}

function confirmRun() { showConfirm.value = true; }

function doRun() {
  router.post(route('accounting.fixed-assets.depreciation.run'), { period: period.value }, {
    onSuccess: () => { showConfirm.value = false; preview.value = []; previewed.value = false; },
  });
}
</script>
