<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="erp-page-header">
        <h1 class="text-2xl font-bold text-gray-900">Dòng tiền tài khoản công ty</h1>
        <button v-if="can('reports.bank_cashflow.reconcile')" class="erp-btn-secondary" @click="showPairModal = true">
          Gợi ý cặp chuyển khoản nội bộ
        </button>
      </div>

      <!-- Bộ lọc -->
      <div class="flex flex-wrap gap-3 rounded-xl border border-gray-200 bg-white p-4">
        <input v-model="filterForm.from" type="date" class="erp-input w-full sm:w-auto" />
        <input v-model="filterForm.to" type="date" class="erp-input w-full sm:w-auto" />
        <select v-model="filterForm.bank_account_id" class="erp-input w-full sm:w-auto">
          <option value="">Tất cả tài khoản</option>
          <option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.bank_name }})</option>
        </select>
        <select v-model="filterForm.cash_flow_category_id" class="erp-input w-full sm:w-auto">
          <option value="">Tất cả nguồn/mục đích</option>
          <option value="null">-- Chưa xác định --</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <select v-model="filterForm.direction" class="erp-input w-full sm:w-auto">
          <option value="">Vào &amp; Ra</option>
          <option value="in">Tiền vào</option>
          <option value="out">Tiền ra</option>
        </select>
        <select v-model="filterForm.reconcile_status" class="erp-input w-full sm:w-auto">
          <option value="">Mọi trạng thái đối soát</option>
          <option value="unclassified">Chưa đối soát</option>
          <option value="party_identified">Đã xác định đối tượng</option>
          <option value="categorized">Đã phân loại</option>
          <option value="document_linked">Đã liên kết chứng từ</option>
          <option value="completed">Hoàn tất</option>
          <option value="needs_review">Chuyển nội bộ – chưa có đối ứng</option>
        </select>
        <RemoteSearchSelect
          v-model="filterForm.project_id"
          :display-text="filterForm.project_name"
          :search-url="route('search.projects')"
          placeholder="Tìm dự án..."
          class="w-full sm:w-56"
          @change="opt => { filterForm.project_name = opt?.label ?? '' }"
        />
        <input v-model="filterForm.search" class="erp-input w-full sm:w-64" placeholder="Tìm nội dung/số TK/đối tượng..." />
        <button class="erp-btn-primary" @click="applyFilters">Lọc</button>
      </div>

      <!-- KPI -->
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiCard label="Số dư đầu kỳ" :value="summary.opening_balance" :hint="balanceHint" />
        <KpiCard label="Tiền vào" :value="summary.inflow" tone="in" />
        <KpiCard label="Tiền ra" :value="summary.outflow" tone="out" />
        <KpiCard label="Dòng tiền thuần" :value="summary.net_cash_flow" :tone="summary.net_cash_flow >= 0 ? 'in' : 'out'" />
        <KpiCard label="Số dư cuối kỳ" :value="summary.closing_balance" :hint="balanceHint" />
        <KpiCard label="Tiền vào chưa xác định nguồn" :value="summary.unclassified_inflow" tone="warn" clickable @click="filterUnclassified('in')" />
        <KpiCard label="Tiền ra chưa xác định mục đích" :value="summary.unclassified_outflow" tone="warn" clickable @click="filterUnclassified('out')" />
      </div>

      <!-- Tổng hợp nguồn vào/ra -->
      <div class="rounded-xl border border-gray-200 bg-white p-4">
        <h2 class="mb-3 text-sm font-semibold text-gray-700">Tổng hợp theo nguồn tiền / mục đích chi</h2>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50">
              <tr>
                <th class="px-4 py-2 text-left font-semibold text-gray-600">Nguồn tiền / Mục đích</th>
                <th class="px-4 py-2 text-right font-semibold text-gray-600">Số GD</th>
                <th class="px-4 py-2 text-right font-semibold text-gray-600">Tổng vào</th>
                <th class="px-4 py-2 text-right font-semibold text-gray-600">Tổng ra</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="row in byCategory" :key="row.category_id ?? 'none'" class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-900">{{ row.category_name }}</td>
                <td class="px-4 py-2 text-right text-gray-600">{{ row.tx_count }}</td>
                <td class="px-4 py-2 text-right text-green-600">{{ row.total_in ? formatVnd(row.total_in) : '—' }}</td>
                <td class="px-4 py-2 text-right text-red-600">{{ row.total_out ? formatVnd(row.total_out) : '—' }}</td>
              </tr>
              <tr v-if="!byCategory.length">
                <td colspan="4" class="px-4 py-8 text-center text-gray-400">Chưa có dữ liệu</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Bảng giao dịch -->
      <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full text-sm">
          <thead class="border-b border-gray-200 bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Ngày</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Tài khoản</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Nội dung</th>
              <th class="px-4 py-2 text-right font-semibold text-gray-600">Tiền vào</th>
              <th class="px-4 py-2 text-right font-semibold text-gray-600">Tiền ra</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Đối tượng</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Nguồn/Mục đích</th>
              <th class="px-4 py-2 text-left font-semibold text-gray-600">Đối soát</th>
              <th class="px-4 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="t in transactions.data" :key="t.id" class="hover:bg-gray-50">
              <td class="px-4 py-2 text-gray-600">{{ t.transaction_date }}</td>
              <td class="px-4 py-2 text-gray-900">{{ t.bank_account_name }}</td>
              <td class="px-4 py-2 text-gray-900">
                {{ t.description }}
                <span
                  v-if="t.is_paired"
                  class="ml-1 text-xs text-purple-600"
                  :title="t.paired_with ? `Đối ứng: ${t.paired_with.bank_account_name} · ${t.paired_with.transaction_date} · ${formatVnd(t.paired_with.amount)}` : ''"
                >(nội bộ đã cặp)</span>
              </td>
              <td class="px-4 py-2 text-right font-medium text-green-600">{{ t.credit ? formatVnd(t.credit) : '' }}</td>
              <td class="px-4 py-2 text-right font-medium text-red-600">{{ t.debit ? formatVnd(t.debit) : '' }}</td>
              <td class="px-4 py-2 text-gray-600">
                <template v-if="t.party_name">{{ t.party_name }} <span class="text-xs text-gray-400">({{ t.party_type_label }})</span></template>
                <template v-else>—</template>
              </td>
              <td class="px-4 py-2 text-gray-600">{{ t.category_name || '—' }}</td>
              <td class="px-4 py-2"><StatusBadge :color="t.reconcile_status_color">{{ t.reconcile_status_label }}</StatusBadge></td>
              <td class="px-4 py-2 text-right whitespace-nowrap">
                <button v-if="can('reports.bank_cashflow.reconcile')" class="text-primary-600 hover:text-primary-800 text-xs font-medium mr-3" @click="openClassify(t)">Phân loại</button>
                <button v-if="t.is_paired && can('reports.bank_cashflow.reconcile')" class="text-red-600 hover:text-red-800 text-xs font-medium" @click="unpair(t)">Hủy cặp</button>
              </td>
            </tr>
            <tr v-if="!transactions.data?.length">
              <td colspan="9" class="px-4 py-10 text-center text-gray-400">Chưa có giao dịch</td>
            </tr>
          </tbody>
        </table>
        <div class="p-4"><Pagination :links="transactions.links" :meta="transactions.meta" /></div>
      </div>
    </div>

    <ClassifyModal v-if="activeTx" :transaction="activeTx" :categories="categories" @close="activeTx = null" @saved="activeTx = null" />
    <PairSuggestionsModal v-if="showPairModal" @close="showPairModal = false" @paired="onPaired" />
  </AppLayout>
</template>

<script setup>
import { computed, reactive, ref, h } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
import { usePermission } from '@/composables/usePermission';
import { formatVnd } from '@/composables/useCurrency';
import ClassifyModal from './ClassifyModal.vue';
import PairSuggestionsModal from './PairSuggestionsModal.vue';

const { hasPermission: can } = usePermission();

const props = defineProps({
  filters: Object,
  summary: Object,
  transactions: Object,
  byCategory: Array,
  bankAccounts: Array,
  categories: Array,
});

// Backend trả balance_source (spec §7 — CompanyCashFlowReportService::summary()) — chỉ hiện
// cảnh báo "số dư tính toán, chưa xác nhận ngân hàng" khi backend thật sự đánh dấu vậy, không
// hard-code cố định (nếu sau này có nguồn số dư khác, cảnh báo phải tự tắt theo backend).
const balanceHint = computed(() =>
  props.summary.balance_source === 'calculated'
    ? 'Số dư tính từ dữ liệu giao dịch hiện có, chưa phải số dư xác nhận trực tiếp từ ngân hàng'
    : null
);

const filterForm = reactive({
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
  bank_account_id: props.filters.bank_account_id ?? '',
  cash_flow_category_id: props.filters.cash_flow_category_id ?? '',
  direction: props.filters.direction ?? '',
  reconcile_status: props.filters.reconcile_status ?? '',
  project_id: props.filters.project_id ?? '',
  project_name: '',
  search: props.filters.search ?? '',
});

const activeTx = ref(null);
const showPairModal = ref(false);

function applyFilters() {
  const payload = { ...filterForm };
  delete payload.project_name;
  // KHÔNG convert string "null" -> JS null: một query string GET không phân biệt được
  // value null với value '' (cả 2 đều serialize thành "key=") — gửi nguyên string "null"
  // để backend (CompanyCashFlowReportService::baseQuery()) phân biệt được "chưa chọn"
  // (rỗng, bị strip) với "lọc tường minh -- Chưa xác định --" (string "null", giữ lại).
  // Phát hiện qua pre-deploy audit: convert ở đây làm mọi giao dịch ĐÃ phân loại biến mất
  // khỏi báo cáo mỗi khi bấm Lọc mà không chọn category cụ thể.
  router.get(route('reports.company-cashflow.index'), payload, { preserveState: true });
}

function filterUnclassified(direction) {
  filterForm.cash_flow_category_id = 'null';
  filterForm.direction = direction;
  applyFilters();
}

function openClassify(t) {
  activeTx.value = t;
}

function unpair(t) {
  if (!confirm('Hủy cặp chuyển khoản nội bộ của giao dịch này?')) return;
  router.post(route('reports.company-cashflow.unpair', t.id), {}, { preserveScroll: true });
}

function onPaired() {
  showPairModal.value = false;
  router.reload({ only: ['summary', 'transactions', 'byCategory'] });
}

// Local KPI card component (Phase 1 — số liệu, chưa biểu đồ)
const KpiCard = {
  props: { label: String, value: Number, tone: { type: String, default: 'neutral' }, clickable: Boolean, hint: String },
  emits: ['click'],
  setup(p, { emit }) {
    const toneClass = { in: 'text-green-600', out: 'text-red-600', warn: 'text-amber-600', neutral: 'text-gray-900' }[p.tone];
    return () => h('div', {
      class: ['rounded-xl border border-gray-200 bg-white p-4', p.clickable ? 'cursor-pointer hover:border-primary-300' : ''],
      onClick: () => p.clickable && emit('click'),
      title: p.hint || undefined,
    }, [
      h('p', { class: 'text-xs text-gray-500' }, p.label),
      h('p', { class: ['mt-1 text-lg font-semibold text-right', toneClass] }, formatVnd(p.value ?? 0)),
      p.hint ? h('p', { class: 'mt-0.5 text-[11px] text-gray-400 text-right' }, p.hint) : null,
    ]);
  },
};
</script>
