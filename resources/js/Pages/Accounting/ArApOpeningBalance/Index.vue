<template>
  <AppLayout>
    <div class="max-w-7xl">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Công nợ đầu kỳ</h1>
          <p class="text-sm text-gray-500 mt-1">Số dư phải thu / phải trả đầu kỳ theo từng đối tượng và hóa đơn</p>
        </div>
        <Link :href="route('accounting.ar-ap-opening-balance.create', { type: activeType })"
          class="btn-primary">+ Nhập đầu kỳ mới</Link>
      </div>

      <!-- Type tabs + Filters -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
        <div class="flex gap-4 mb-4">
          <button @click="switchType('ar')"
            :class="activeType === 'ar' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-600'"
            class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors">
            Phải thu (AR)
          </button>
          <button @click="switchType('ap')"
            :class="activeType === 'ap' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-600'"
            class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors">
            Phải trả (AP)
          </button>
        </div>
        <div class="flex gap-3">
          <div>
            <label class="form-label">Kỳ</label>
            <input v-model="period" type="month" @change="applyFilters" class="form-input" />
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Mã</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">
                {{ activeType === 'ar' ? 'Khách hàng' : 'Nhà cung cấp' }}
              </th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600">Số HĐ/CT</th>
              <th class="px-4 py-3 text-center font-semibold text-gray-600">Ngày HĐ</th>
              <th class="px-4 py-3 text-center font-semibold text-gray-600">Hạn TT</th>
              <th class="px-4 py-3 text-right font-semibold text-gray-600">Giá trị HĐ</th>
              <th class="px-4 py-3 text-right font-semibold text-gray-600">Còn phải {{ activeType === 'ar' ? 'thu' : 'trả' }}</th>
              <th class="px-4 py-3 text-center font-semibold text-gray-600">BT kế toán</th>
              <th class="px-4 py-3 text-center font-semibold text-gray-600">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="!balances.length">
              <td colspan="9" class="px-4 py-8 text-center text-gray-400">Chưa có dữ liệu công nợ đầu kỳ</td>
            </tr>
            <tr v-for="b in balances" :key="b.id" class="hover:bg-gray-50">
              <td class="px-4 py-2.5 font-mono text-xs text-gray-500">{{ b.party_code }}</td>
              <td class="px-4 py-2.5 font-medium">{{ b.party_name }}</td>
              <td class="px-4 py-2.5 font-mono text-xs">{{ b.invoice_ref ?? '—' }}</td>
              <td class="px-4 py-2.5 text-center text-xs text-gray-600">{{ b.invoice_date ?? '—' }}</td>
              <td class="px-4 py-2.5 text-center text-xs text-gray-600">{{ b.due_date ?? '—' }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ fv(b.amount) }}</td>
              <td class="px-4 py-2.5 text-right font-mono font-semibold"
                :class="b.remaining_amount > 0 ? 'text-orange-700' : b.remaining_amount < 0 ? 'text-red-600' : 'text-gray-400'">
                {{ fv(b.remaining_amount) }}
              </td>
              <td class="px-4 py-2.5 text-center">
                <span v-if="b.has_je" class="text-green-600 text-xs font-semibold">
                  ✓ {{ jeLabel(b) }}
                </span>
                <span v-else class="text-gray-400 text-xs">—</span>
              </td>
              <td class="px-4 py-2.5 text-center">
                <button v-if="!b.has_je" @click="deleteRow(b.id)"
                  class="text-red-500 hover:text-red-700 text-xs">Xóa</button>
              </td>
            </tr>
          </tbody>
          <tfoot v-if="balances.length" class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td colspan="5" class="px-4 py-2 text-right font-semibold text-gray-600">Tổng cộng:</td>
              <td class="px-4 py-2 text-right font-mono font-bold">{{ fv(totalAmount) }}</td>
              <td class="px-4 py-2 text-right font-mono font-bold text-orange-700">{{ fv(totalRemaining) }}</td>
              <td colspan="2" />
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ balances: Array, filters: Object });
const { formatDecimalVnd: fv } = useCurrency();

const activeType = ref(props.filters.type ?? 'ar');
const period     = ref(props.filters.period ?? '');

function jeLabel(b) {
  if (b.type === 'ar') {
    return b.remaining_amount >= 0 ? 'Nợ 131 / Có 411' : 'Có 131 / Nợ 411';
  }
  return b.remaining_amount >= 0 ? 'Có 331 / Nợ 411' : 'Nợ 331 / Có 411';
}

const totalAmount    = computed(() => props.balances.reduce((s, b) => s + b.amount, 0));
const totalRemaining = computed(() => props.balances.reduce((s, b) => s + b.remaining_amount, 0));

function applyFilters() {
  router.get(route('accounting.ar-ap-opening-balance.index'), {
    type:   activeType.value,
    period: period.value || undefined,
  }, { preserveState: true });
}

function switchType(type) {
  activeType.value = type;
  applyFilters();
}

function deleteRow(id) {
  if (!confirm('Xóa dòng công nợ đầu kỳ này?')) return;
  router.delete(route('accounting.ar-ap-opening-balance.destroy', id));
}
</script>
