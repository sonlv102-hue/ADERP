<template>
  <AppLayout title="Báo cáo chuyển khoản nội bộ">
    <div class="space-y-5">

      <!-- Header -->
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Báo cáo chuyển khoản nội bộ</h1>
          <p class="text-sm text-slate-500 mt-0.5">Giám sát hồ sơ đối ứng và hoàn ứng theo tháng</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <!-- Multi-select filter: TK nội bộ -->
          <div class="relative" ref="accountDropdownRef">
            <button type="button" @click="accountDropdownOpen = !accountDropdownOpen"
              class="erp-input text-sm min-w-[220px] flex items-center justify-between gap-2 cursor-pointer">
              <span class="truncate text-left">
                <template v-if="selectedAccounts.length === 0">Chọn một hoặc nhiều tài khoản</template>
                <template v-else-if="selectedAccounts.length === 1">{{ selectedAccountLabels[0] }}</template>
                <template v-else>{{ selectedAccounts.length }} tài khoản đã chọn</template>
              </span>
              <span class="flex shrink-0 items-center gap-1">
                <span v-if="selectedAccounts.length > 0"
                  class="bg-purple-600 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center font-bold leading-none">
                  {{ selectedAccounts.length }}
                </span>
                <svg class="w-4 h-4 text-slate-400 transition-transform" :class="{ 'rotate-180': accountDropdownOpen }"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
              </span>
            </button>

            <!-- Dropdown panel -->
            <div v-show="accountDropdownOpen"
              class="absolute top-full right-0 mt-1 bg-white border border-slate-200 rounded-lg shadow-lg z-50 min-w-[270px] max-h-72 flex flex-col">
              <div class="px-3 py-2 border-b border-slate-100 flex items-center justify-between shrink-0">
                <span class="text-xs text-slate-500 font-medium">Lọc theo tài khoản nội bộ</span>
                <button v-if="selectedAccounts.length > 0"
                  @click="clearAndApply"
                  class="text-xs text-red-500 hover:text-red-700 font-medium">Xóa bộ lọc</button>
              </div>
              <div class="overflow-y-auto flex-1">
                <label v-for="acc in allInternalAccounts" :key="acc.id"
                  class="flex items-start gap-3 px-3 py-2.5 hover:bg-slate-50 cursor-pointer border-b border-slate-50 last:border-0">
                  <input type="checkbox" :value="acc.id" v-model="selectedAccounts"
                    class="mt-0.5 rounded text-primary-600 focus:ring-primary-500 shrink-0" />
                  <div class="min-w-0">
                    <div class="text-sm font-medium text-slate-800 truncate">{{ acc.name }}</div>
                    <div class="text-xs text-slate-400">
                      {{ acc.account_number }}<span v-if="acc.bank_name"> · {{ acc.bank_name }}</span>
                    </div>
                  </div>
                </label>
                <div v-if="allInternalAccounts.length === 0" class="px-3 py-5 text-xs text-slate-400 text-center">
                  Không có tài khoản nội bộ
                </div>
              </div>
              <div class="px-3 py-2 border-t border-slate-100 shrink-0">
                <button @click="applyFilters(); accountDropdownOpen = false"
                  class="w-full text-sm font-semibold text-white bg-primary-600 hover:bg-primary-700 rounded-lg py-1.5 transition-colors">
                  Áp dụng
                </button>
              </div>
            </div>
          </div>

          <!-- Filter tháng -->
          <select v-model="selectedMonth" @change="applyFilters"
            class="erp-input w-40 text-sm">
            <option v-for="m in availableMonths" :key="m" :value="m">{{ formatMonth(m) }}</option>
            <option v-if="!availableMonths.includes(currentMonth)" :value="currentMonth">
              {{ formatMonth(currentMonth) }}
            </option>
          </select>
        </div>
      </div>

      <!-- Active filter chips -->
      <div v-if="selectedAccounts.length > 0" class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-slate-500 font-medium">Đang lọc:</span>
        <span v-for="acc in selectedAccountObjects" :key="acc.id"
          class="inline-flex items-center gap-1.5 bg-purple-100 text-purple-800 text-xs font-semibold px-3 py-1.5 rounded-full border border-purple-200">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/>
          </svg>
          {{ acc.name }}
          <button @click="removeAccount(acc.id)" class="ml-0.5 hover:text-purple-600">✕</button>
        </span>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
          <p class="text-xs text-slate-500 font-medium uppercase tracking-wide">Tổng chuyển ra</p>
          <p class="text-xl font-bold text-red-600 mt-1">{{ formatVnd(summary.total_debit) }}</p>
          <p class="text-xs text-slate-400 mt-0.5">{{ summary.count }} giao dịch</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
          <p class="text-xs text-slate-500 font-medium uppercase tracking-wide">Tổng nhận về</p>
          <p class="text-xl font-bold text-green-600 mt-1">{{ formatVnd(summary.total_credit) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
          <p class="text-xs text-slate-500 font-medium uppercase tracking-wide">Chênh lệch (ra − vào)</p>
          <p class="text-xl font-bold mt-1" :class="summary.net < 0 ? 'text-red-600' : 'text-slate-800'">
            {{ formatVnd(Math.abs(summary.net)) }}
            <span class="text-sm font-normal text-slate-400">{{ summary.net < 0 ? '(ra nhiều hơn)' : '(vào nhiều hơn)' }}</span>
          </p>
        </div>
        <div class="bg-amber-50 rounded-xl border border-amber-200 p-4">
          <p class="text-xs text-amber-600 font-medium uppercase tracking-wide">Chưa xử lý hồ sơ</p>
          <p class="text-xl font-bold text-amber-700 mt-1">{{ summary.pending_count }}</p>
          <p v-if="summary.needs_return > 0" class="text-xs text-red-600 font-semibold mt-0.5">
            Cần hoàn: {{ formatVnd(summary.needs_return) }}
          </p>
        </div>
      </div>

      <!-- Transactions table -->
      <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
          <h2 class="text-sm font-semibold text-slate-700">Chi tiết giao dịch — {{ formatMonth(month) }}</h2>
          <div class="flex gap-2">
            <button v-for="s in statusFilters" :key="s.value"
              @click="filterStatus = filterStatus === s.value ? '' : s.value"
              class="text-xs px-2.5 py-1 rounded-full border font-medium transition-colors"
              :class="filterStatus === s.value ? s.activeClass : 'border-slate-200 text-slate-500 hover:bg-slate-50'">
              {{ s.label }}
              <span class="ml-1 font-bold">{{ countByStatus(s.value) }}</span>
            </button>
          </div>
        </div>

        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide w-24">Ngày</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Diễn giải / Đối tác</th>
              <th class="text-right px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide w-32">Tiền ra (−)</th>
              <th class="text-right px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide w-32">Tiền vào (+)</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide w-36">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Ghi chú / Hoàn ứng</th>
              <th v-if="can('accounting.manage')" class="px-5 py-3 w-16"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <template v-for="tx in filteredTransactions" :key="tx.id">
              <tr class="hover:bg-slate-50/70 transition-colors"
                :class="{
                  'bg-red-50/40':   tx.internal_status === 'needs_return',
                  'bg-amber-50/30': tx.internal_status === 'pending',
                  'bg-green-50/20': tx.internal_status === 'returned',
                }">
                <td class="px-5 py-3 text-slate-500 text-xs whitespace-nowrap">{{ tx.transaction_date }}</td>
                <td class="px-5 py-3">
                  <div class="text-slate-800 font-medium">{{ tx.description }}</div>
                  <div v-if="tx.counterpart_account" class="text-xs text-slate-400 mt-0.5">
                    <span class="font-mono">{{ tx.counterpart_account }}</span>
                    <span v-if="tx.counterpart_name"> · {{ tx.counterpart_name }}</span>
                    <span v-if="tx.internal_account" class="ml-1 text-purple-600 font-medium">[{{ tx.internal_account }}]</span>
                  </div>
                </td>
                <td class="px-5 py-3 text-right">
                  <span v-if="tx.debit > 0" class="text-red-600 font-semibold">{{ formatVnd(tx.debit) }}</span>
                </td>
                <td class="px-5 py-3 text-right">
                  <span v-if="tx.credit > 0" class="text-green-600 font-semibold">{{ formatVnd(tx.credit) }}</span>
                </td>
                <td class="px-5 py-3">
                  <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold border"
                    :class="statusClass(tx.internal_status)">
                    <span :class="statusDot(tx.internal_status)" class="w-1.5 h-1.5 rounded-full"></span>
                    {{ tx.internal_status_label }}
                  </span>
                </td>
                <td class="px-5 py-3">
                  <div v-if="tx.internal_note" class="text-xs text-slate-600">{{ tx.internal_note }}</div>
                  <div v-if="tx.internal_status === 'needs_return' && tx.return_amount > 0"
                    class="text-xs text-red-600 font-semibold mt-0.5">
                    Cần hoàn: {{ formatVnd(tx.return_amount) }}
                  </div>
                  <div v-if="!tx.internal_note && tx.internal_status === 'pending'"
                    class="text-xs text-slate-400 italic">Chưa cập nhật</div>
                </td>
                <td v-if="can('accounting.manage')" class="px-5 py-3">
                  <button @click="openUpdate(tx)"
                    class="text-xs text-primary-600 hover:text-primary-800 font-medium">Cập nhật</button>
                </td>
              </tr>
            </template>
            <tr v-if="filteredTransactions.length === 0">
              <td :colspan="can('accounting.manage') ? 7 : 6" class="px-5 py-14 text-center text-slate-400">
                <svg class="w-8 h-8 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                Không có giao dịch nào trong tháng này
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Footer totals -->
        <div v-if="filteredTransactions.length > 0"
          class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex justify-end gap-8 text-sm font-semibold">
          <span>Tổng ra: <span class="text-red-600">{{ formatVnd(filteredTransactions.reduce((s,t) => s + t.debit, 0)) }}</span></span>
          <span>Tổng vào: <span class="text-green-600">{{ formatVnd(filteredTransactions.reduce((s,t) => s + t.credit, 0)) }}</span></span>
        </div>
      </div>
    </div>

    <!-- Update status modal -->
    <Modal :show="updateTarget !== null" @close="updateTarget = null">
      <template #title>Cập nhật hồ sơ — {{ updateTarget?.description?.substring(0, 40) }}</template>
      <div class="space-y-4 text-sm">
        <div class="bg-slate-50 rounded-lg px-4 py-3 text-xs text-slate-600 space-y-1">
          <div>Ngày: <strong>{{ updateTarget?.transaction_date }}</strong></div>
          <div v-if="updateTarget?.debit > 0">Tiền ra: <strong class="text-red-600">{{ formatVnd(updateTarget?.debit) }}</strong></div>
          <div v-if="updateTarget?.credit > 0">Tiền vào: <strong class="text-green-600">{{ formatVnd(updateTarget?.credit) }}</strong></div>
          <div v-if="updateTarget?.counterpart_account">Đối tác: <strong>{{ updateTarget?.counterpart_account }}</strong> {{ updateTarget?.counterpart_name }}</div>
        </div>

        <div>
          <label class="erp-label">Trạng thái hồ sơ <span class="text-red-500">*</span></label>
          <select v-model="updateForm.internal_status" class="erp-input">
            <option value="pending">Chưa xử lý</option>
            <option value="docs_done">Đã có hồ sơ đối ứng</option>
            <option value="needs_return">Cần hoàn ứng</option>
            <option value="returned">Đã hoàn ứng</option>
          </select>
        </div>

        <div v-if="updateForm.internal_status === 'needs_return'">
          <label class="erp-label">Số tiền cần hoàn ứng</label>
          <input v-model.number="updateForm.return_amount" type="number" min="0" class="erp-input"
            :placeholder="updateTarget?.debit > 0 ? updateTarget.debit : ''" />
          <p class="text-xs text-slate-400 mt-1">Để trống nếu hoàn toàn bộ số tiền đã chuyển</p>
        </div>

        <div>
          <label class="erp-label">Ghi chú / Mục đích sử dụng</label>
          <textarea v-model="updateForm.internal_note" rows="3" class="erp-input"
            placeholder="VD: Tạm ứng mua vật tư kho, sẽ hoàn ứng sau khi quyết toán..." />
        </div>
      </div>
      <template #footer>
        <button @click="updateTarget = null" class="erp-btn-secondary">Hủy</button>
        <button @click="submitUpdate" class="erp-btn-primary">Lưu</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Modal from '@/Components/Shared/Modal.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();

const props = defineProps({
  month:               String,
  availableMonths:     Array,
  internalAccountIds:  { type: Array, default: () => [] },
  allInternalAccounts: { type: Array, default: () => [] },
  summary:             Object,
  transactions:        Array,
});

const selectedMonth    = ref(props.month);
const selectedAccounts = ref([...props.internalAccountIds]);
const currentMonth     = new Date().toISOString().slice(0, 7);
const filterStatus     = ref('');
const updateTarget     = ref(null);
const updateForm       = ref({ internal_status: 'pending', internal_note: '', return_amount: null });

// Multi-select dropdown
const accountDropdownRef  = ref(null);
const accountDropdownOpen = ref(false);

function onClickOutside(e) {
  if (accountDropdownRef.value && !accountDropdownRef.value.contains(e.target)) {
    accountDropdownOpen.value = false;
  }
}
onMounted(() => document.addEventListener('mousedown', onClickOutside));
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside));

const selectedAccountObjects = computed(() =>
  props.allInternalAccounts.filter(a => selectedAccounts.value.includes(a.id))
);

const selectedAccountLabels = computed(() =>
  selectedAccountObjects.value.map(a => `${a.name} (${a.account_number})`)
);

const statusFilters = [
  { value: 'pending',      label: 'Chưa xử lý',  activeClass: 'bg-amber-100 border-amber-400 text-amber-700' },
  { value: 'docs_done',    label: 'Đã có hồ sơ', activeClass: 'bg-blue-100 border-blue-400 text-blue-700' },
  { value: 'needs_return', label: 'Cần hoàn ứng', activeClass: 'bg-red-100 border-red-400 text-red-700' },
  { value: 'returned',     label: 'Đã hoàn ứng', activeClass: 'bg-green-100 border-green-400 text-green-700' },
];

const filteredTransactions = computed(() => {
  if (!filterStatus.value) return props.transactions;
  return props.transactions.filter(t =>
    filterStatus.value === 'pending'
      ? (t.internal_status === 'pending' || !t.internal_status)
      : t.internal_status === filterStatus.value
  );
});

function countByStatus(status) {
  if (status === 'pending') return props.transactions.filter(t => !t.internal_status || t.internal_status === 'pending').length;
  return props.transactions.filter(t => t.internal_status === status).length;
}

function formatMonth(m) {
  if (!m) return '';
  const [y, mon] = m.split('-');
  return `Tháng ${parseInt(mon)}/${y}`;
}

function applyFilters() {
  const params = { month: selectedMonth.value };
  if (selectedAccounts.value.length > 0) {
    params.internal_account_ids = selectedAccounts.value;
  }
  router.get(route('accounting.internal-transfers.index'), params, { preserveState: false });
}

function clearAndApply() {
  selectedAccounts.value = [];
  accountDropdownOpen.value = false;
  applyFilters();
}

function removeAccount(id) {
  selectedAccounts.value = selectedAccounts.value.filter(v => v !== id);
  applyFilters();
}

function statusClass(s) {
  return {
    'bg-amber-50 border-amber-300 text-amber-700':  !s || s === 'pending',
    'bg-blue-50 border-blue-300 text-blue-700':     s === 'docs_done',
    'bg-red-50 border-red-300 text-red-700':        s === 'needs_return',
    'bg-green-50 border-green-300 text-green-700':  s === 'returned',
  };
}

function statusDot(s) {
  return {
    'bg-amber-400': !s || s === 'pending',
    'bg-blue-500':  s === 'docs_done',
    'bg-red-500':   s === 'needs_return',
    'bg-green-500': s === 'returned',
  };
}

function openUpdate(tx) {
  updateTarget.value = tx;
  updateForm.value = {
    internal_status: tx.internal_status || 'pending',
    internal_note:   tx.internal_note   || '',
    return_amount:   tx.return_amount   || null,
  };
}

function submitUpdate() {
  router.post(
    route('accounting.internal-transfers.update-status', updateTarget.value.id),
    updateForm.value,
    { onSuccess: () => { updateTarget.value = null; } }
  );
}
</script>
