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
          <h1 class="text-2xl font-bold text-slate-900">Ghi nhận sửa chữa / nâng cấp</h1>
          <p class="text-sm text-slate-500 mt-0.5">{{ asset.code }} — {{ asset.name }}</p>
        </div>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="erp-label">Loại sửa chữa <span class="text-red-500">*</span></label>
            <select v-model="form.repair_type" class="erp-input w-full" required>
              <option value="regular">Sửa chữa thường xuyên</option>
              <option value="major_repair">Sửa chữa lớn (phân bổ qua 242)</option>
              <option value="upgrade">Nâng cấp / cải tạo (tăng nguyên giá)</option>
            </select>
          </div>
          <div>
            <label class="erp-label">Ngày <span class="text-red-500">*</span></label>
            <input v-model="form.repair_date" type="date" class="erp-input w-full" required />
          </div>
        </div>

        <div>
          <label class="erp-label">Mô tả <span class="text-red-500">*</span></label>
          <input v-model="form.description" class="erp-input w-full" required />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="erp-label">Số tiền (chưa VAT) <span class="text-red-500">*</span></label>
            <input v-model.number="form.amount" type="number" min="0" step="1" class="erp-input w-full" required />
          </div>
          <div>
            <label class="erp-label">VAT</label>
            <input v-model.number="form.vat_amount" type="number" min="0" step="1" class="erp-input w-full" />
          </div>
        </div>

        <!-- Accounting treatment (auto-set but let user override) -->
        <div>
          <label class="erp-label">Hạch toán <span class="text-red-500">*</span></label>
          <select v-model="form.accounting_treatment" class="erp-input w-full">
            <option value="expense_now">Ghi vào chi phí ngay (Dr 154/6421)</option>
            <option value="prepaid_allocation">Phân bổ qua chi phí trả trước (Dr 242)</option>
            <option value="increase_original_cost">Tăng nguyên giá TSCĐ (Dr 241 → Dr 211)</option>
          </select>
        </div>

        <div v-if="form.accounting_treatment === 'prepaid_allocation'">
          <label class="erp-label">Số tháng phân bổ</label>
          <input v-model.number="form.allocation_months" type="number" min="1" class="erp-input w-full" />
        </div>

        <div>
          <label class="erp-label">Ghi chú</label>
          <textarea v-model="form.notes" class="erp-input w-full" rows="2" />
        </div>

        <label class="flex items-center gap-3 cursor-pointer">
          <input v-model="form.create_journal" type="checkbox" class="erp-checkbox" />
          <span class="text-sm text-slate-700">Tự động tạo bút toán nháp</span>
        </label>

        <div class="flex justify-end gap-3">
          <Link :href="route('accounting.fixed-assets.show', asset.id)" class="erp-btn-secondary">Hủy</Link>
          <button type="submit" class="erp-btn-primary">Ghi nhận</button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({ asset: Object, repair: Object });

const form = ref({
  repair_type: props.repair?.repair_type || 'regular',
  repair_date: props.repair?.repair_date || '',
  description: props.repair?.description || '',
  amount: props.repair?.amount || 0,
  vat_amount: props.repair?.vat_amount || 0,
  accounting_treatment: props.repair?.accounting_treatment || 'expense_now',
  allocation_months: props.repair?.allocation_months || null,
  notes: props.repair?.notes || '',
  create_journal: true,
});

// Auto-suggest accounting treatment based on repair type
watch(() => form.value.repair_type, (type) => {
  if (type === 'regular') form.value.accounting_treatment = 'expense_now';
  else if (type === 'major_repair') form.value.accounting_treatment = 'prepaid_allocation';
  else if (type === 'upgrade') form.value.accounting_treatment = 'increase_original_cost';
});

function submit() {
  router.post(route('accounting.fixed-assets.repairs.store', props.asset.id), form.value);
}
</script>
