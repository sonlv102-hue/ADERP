<template>
  <AppLayout>
    <div class="max-w-xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.small-tools.show', tool.id)" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Xử lý CCDC hỏng/mất/thanh lý</h1>
      </div>

      <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm mb-5">
        <span class="font-semibold text-red-800">{{ tool.code }}</span>
        <span class="text-red-700 ml-2">{{ tool.name }}</span>
        <div class="mt-1 text-xs text-red-600">
          Giá trị còn lại: <span class="font-semibold font-mono">{{ formatVnd(tool.total_remaining) }}</span>
        </div>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div>
          <label class="erp-label">Loại xử lý <span class="text-red-500">*</span></label>
          <select v-model="form.disposal_type" class="erp-input">
            <option value="broken">Báo hỏng</option>
            <option value="lost">Báo mất</option>
            <option value="liquidated">Thanh lý / Nhượng bán</option>
          </select>
        </div>

        <div>
          <label class="erp-label">Ngày xử lý <span class="text-red-500">*</span></label>
          <input v-model="form.disposal_date" type="date" class="erp-input"
            :class="{ 'border-red-500': form.errors.disposal_date }" />
        </div>

        <div>
          <label class="erp-label">Lý do <span class="text-red-500">*</span></label>
          <textarea v-model="form.reason" rows="2" class="erp-input"
            :class="{ 'border-red-500': form.errors.reason }" />
          <p v-if="form.errors.reason" class="erp-error">{{ form.errors.reason }}</p>
        </div>

        <div>
          <label class="erp-label">TK ghi nhận tổn thất <span class="text-red-500">*</span></label>
          <select v-model="form.expense_account_code" class="erp-input">
            <option value="6422">6422 — Chi phí quản lý</option>
            <option value="6421">6421 — Chi phí bán hàng</option>
            <option value="8111">811 — Chi phí khác</option>
            <option value="13811">1381 — Tài sản thiếu chờ xử lý</option>
          </select>
        </div>

        <!-- Thanh lý có thu hồi -->
        <template v-if="form.disposal_type === 'liquidated'">
          <div class="border-t pt-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Thu hồi từ thanh lý (nếu có)</h3>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="erp-label">Số tiền thu hồi</label>
                <input v-model.number="form.recovery_amount" type="number" min="0" class="erp-input" />
              </div>
              <div>
                <label class="erp-label">TK doanh thu thanh lý</label>
                <select v-model="form.recovery_account_code" class="erp-input">
                  <option value="7111">711 — Thu nhập khác</option>
                  <option value="51181">5118 — Doanh thu nhượng bán</option>
                </select>
              </div>
              <div>
                <label class="erp-label">VAT thu hồi</label>
                <input v-model.number="form.recovery_vat_amount" type="number" min="0" class="erp-input" />
              </div>
              <div>
                <label class="erp-label">Chi phí thanh lý</label>
                <input v-model.number="form.disposal_cost" type="number" min="0" class="erp-input" />
              </div>
            </div>
          </div>
        </template>

        <!-- JE Preview -->
        <div class="bg-orange-50 border border-orange-200 rounded-lg px-4 py-3 text-xs text-orange-800 space-y-1">
          <p class="font-semibold">Bút toán sẽ sinh:</p>
          <template v-if="tool.total_remaining > 0">
            <p>Nợ {{ form.expense_account_code }} — Tổn thất CCDC: {{ formatVnd(tool.total_remaining) }}</p>
            <p>Có 2422/1531 — Xóa sổ CCDC</p>
          </template>
          <template v-if="form.disposal_type === 'liquidated' && form.recovery_amount > 0">
            <p>Nợ 1111 — Thu tiền thanh lý: {{ formatVnd(form.recovery_amount + (form.recovery_vat_amount || 0)) }}</p>
            <p>Có {{ form.recovery_account_code || '711' }} — Doanh thu thanh lý</p>
            <p v-if="form.recovery_vat_amount > 0">Có 3331 — VAT đầu ra</p>
          </template>
          <p v-if="tool.total_remaining <= 0" class="text-orange-600">
            CCDC đã phân bổ hết, không phát sinh tổn thất.
          </p>
        </div>

        <div>
          <label class="erp-label">Ghi chú</label>
          <textarea v-model="form.notes" rows="2" class="erp-input" />
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-red-600 hover:bg-red-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang xử lý...' : 'Xác nhận xử lý CCDC' }}
          </button>
          <Link :href="route('accounting.small-tools.show', tool.id)"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const { formatVnd } = useCurrency();
const props = defineProps({ tool: Object });

const form = useForm({
  disposal_type:          'broken',
  disposal_date:          new Date().toISOString().slice(0, 10),
  reason:                 '',
  expense_account_code:   '6422',
  recovery_amount:        0,
  recovery_account_code:  '7111',
  recovery_vat_amount:    0,
  disposal_cost:          0,
  notes:                  '',
});

function submit() {
  form.post(route('accounting.small-tools.disposals.store', props.tool.id));
}
</script>
