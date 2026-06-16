<template>
  <AppLayout>
    <div class="max-w-2xl mx-auto space-y-5">
      <div class="flex items-center gap-3">
        <Link :href="route('accounting.fixed-assets.show', asset.id)" class="text-slate-400 hover:text-slate-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Thanh lý / nhượng bán TSCĐ</h1>
          <p class="text-sm text-slate-500 mt-0.5">{{ asset.code }} — {{ asset.name }}</p>
        </div>
      </div>

      <!-- Summary -->
      <div class="grid grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
          <p class="text-xs text-slate-500 font-semibold uppercase">Nguyên giá</p>
          <p class="text-xl font-bold text-slate-900 mt-1">{{ fmt(asset.acquisition_cost) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
          <p class="text-xs text-slate-500 font-semibold uppercase">Hao mòn LK</p>
          <p class="text-xl font-bold text-red-600 mt-1">{{ fmt(asset.accumulated_depreciation) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
          <p class="text-xs text-slate-500 font-semibold uppercase">Giá trị còn lại</p>
          <p class="text-xl font-bold text-indigo-700 mt-1">{{ fmt(asset.net_book_value) }}</p>
        </div>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="erp-label">Loại thanh lý <span class="text-red-500">*</span></label>
            <select v-model="form.disposal_type" class="erp-input w-full" required>
              <option value="liquidation">Thanh lý</option>
              <option value="sale">Nhượng bán</option>
              <option value="damage">Mất mát / Hư hỏng</option>
              <option value="other">Khác</option>
            </select>
          </div>
          <div>
            <label class="erp-label">Ngày thanh lý <span class="text-red-500">*</span></label>
            <input v-model="form.disposal_date" type="date" class="erp-input w-full" required />
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="erp-label">Giá bán (nếu có)</label>
            <input v-model.number="form.selling_price" type="number" min="0" step="1" class="erp-input w-full" @input="recalc" />
          </div>
          <div>
            <label class="erp-label">VAT giá bán</label>
            <input v-model.number="form.selling_vat_amount" type="number" min="0" step="1" class="erp-input w-full" />
          </div>
          <div>
            <label class="erp-label">Chi phí thanh lý</label>
            <input v-model.number="form.disposal_cost" type="number" min="0" step="1" class="erp-input w-full" @input="recalc" />
          </div>
          <div>
            <label class="erp-label">VAT chi phí</label>
            <input v-model.number="form.disposal_vat_amount" type="number" min="0" step="1" class="erp-input w-full" />
          </div>
        </div>

        <!-- Gain/Loss preview -->
        <div class="bg-slate-50 rounded-lg p-4 text-sm">
          <div class="flex justify-between">
            <span class="text-slate-600">Giá trị còn lại (NBV)</span>
            <span class="font-mono font-semibold">{{ fmt(asset.net_book_value) }}</span>
          </div>
          <div class="flex justify-between mt-1">
            <span class="text-slate-600">Giá bán</span>
            <span class="font-mono text-green-700">+ {{ fmt(form.selling_price) }}</span>
          </div>
          <div class="flex justify-between mt-1">
            <span class="text-slate-600">Chi phí thanh lý</span>
            <span class="font-mono text-red-600">- {{ fmt(form.disposal_cost) }}</span>
          </div>
          <div class="border-t border-slate-200 mt-2 pt-2 flex justify-between font-semibold">
            <span>Lãi/(Lỗ) ước tính</span>
            <span :class="gainLoss >= 0 ? 'text-green-700' : 'text-red-600'">{{ fmt(gainLoss) }}</span>
          </div>
        </div>

        <div>
          <label class="erp-label">Tên người mua / đơn vị nhận</label>
          <input v-model="form.buyer_name" class="erp-input w-full" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="erp-label">TK chi phí thanh lý</label>
            <input v-model="form.disposal_account_code" class="erp-input w-full font-mono" placeholder="811" />
          </div>
          <div>
            <label class="erp-label">TK thu nhập</label>
            <input v-model="form.income_account_code" class="erp-input w-full font-mono" placeholder="711" />
          </div>
        </div>

        <div>
          <label class="erp-label">Ghi chú</label>
          <textarea v-model="form.notes" class="erp-input w-full" rows="2" />
        </div>

        <label class="flex items-center gap-3 cursor-pointer">
          <input v-model="form.create_journal" type="checkbox" class="erp-checkbox" />
          <span class="text-sm text-slate-700">Tự động tạo bút toán nháp (xóa sổ, doanh thu, chi phí)</span>
        </label>

        <div class="flex justify-end gap-3">
          <Link :href="route('accounting.fixed-assets.show', asset.id)" class="erp-btn-secondary">Hủy</Link>
          <button type="submit" class="erp-btn-danger">Ghi nhận thanh lý</button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ asset: Object });
const { formatVnd } = useCurrency();
const fmt = (v) => formatVnd(v);

const form = ref({
  disposal_type: 'liquidation',
  disposal_date: '',
  selling_price: 0,
  selling_vat_amount: 0,
  disposal_cost: 0,
  disposal_vat_amount: 0,
  buyer_name: '',
  disposal_account_code: '811',
  income_account_code: '711',
  notes: '',
  create_journal: true,
});

const gainLoss = computed(() =>
  (form.value.selling_price || 0) - (form.value.disposal_cost || 0) - (props.asset.net_book_value || 0)
);

function recalc() { /* gainLoss is computed */ }

function submit() {
  router.post(route('accounting.fixed-assets.disposals.store', props.asset.id), form.value);
}
</script>
