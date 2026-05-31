<template>
  <AppLayout :title="`Giao dịch — ${bankAccount.name}`">
    <div class="max-w-6xl mx-auto">

      <!-- Header -->
      <div class="flex items-center gap-4 mb-6">
        <Link :href="route('accounting.bank-accounts.index')" class="text-gray-500 hover:text-gray-700">←</Link>
        <div class="flex-1">
          <h1 class="text-2xl font-bold text-gray-900">{{ bankAccount.name }}</h1>
          <p class="text-sm text-gray-500">{{ bankAccount.bank_name }} · {{ bankAccount.account_number }} · TK {{ bankAccount.account_code }}</p>
        </div>
        <div class="text-right">
          <p class="text-xs text-gray-500">Số dư hiện tại</p>
          <p class="text-xl font-bold" :class="bankAccount.balance >= 0 ? 'text-green-600' : 'text-red-600'">
            {{ formatVnd(bankAccount.balance) }}
          </p>
        </div>
      </div>

      <!-- Add transaction form (accounting.manage only) -->
      <div v-if="can('accounting.manage')" class="bg-white rounded-xl shadow-sm p-5 mb-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Thêm giao dịch ngân hàng</h3>
        <form @submit.prevent="submitAdd" class="grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
          <div class="col-span-2">
            <label class="form-label text-xs">Diễn giải <span class="text-red-500">*</span></label>
            <input v-model="addForm.description" class="form-input text-sm" placeholder="Thu tiền khách hàng..." />
          </div>
          <div>
            <label class="form-label text-xs">Ngày <span class="text-red-500">*</span></label>
            <input v-model="addForm.transaction_date" type="date" class="form-input text-sm" />
          </div>
          <div>
            <label class="form-label text-xs">Số tiền vào (Có)</label>
            <input v-model.number="addForm.credit" type="number" min="0" step="1" class="form-input text-sm" placeholder="0" />
          </div>
          <div>
            <label class="form-label text-xs">Số tiền ra (Nợ)</label>
            <input v-model.number="addForm.debit" type="number" min="0" step="1" class="form-input text-sm" placeholder="0" />
          </div>
          <div>
            <button type="submit" :disabled="addForm.processing" class="btn-primary text-sm w-full">
              {{ addForm.processing ? '...' : 'Thêm' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl shadow-sm p-4 mb-4 flex flex-wrap gap-4">
        <div>
          <label class="form-label text-xs">Trạng thái</label>
          <select v-model="filters.status" class="form-input text-sm">
            <option value="">Tất cả</option>
            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div>
          <label class="form-label text-xs">Từ ngày</label>
          <input v-model="filters.date_from" type="date" class="form-input text-sm" />
        </div>
        <div>
          <label class="form-label text-xs">Đến ngày</label>
          <input v-model="filters.date_to" type="date" class="form-input text-sm" />
        </div>
        <div class="flex items-end">
          <button @click="applyFilters" class="btn-secondary text-sm">Lọc</button>
        </div>
      </div>

      <!-- Transactions table -->
      <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
            <tr>
              <th class="px-4 py-3 text-left w-28">Ngày</th>
              <th class="px-4 py-3 text-left">Diễn giải</th>
              <th class="px-4 py-3 text-left w-28">Số CT</th>
              <th class="px-4 py-3 text-right w-32">Tiền vào (+)</th>
              <th class="px-4 py-3 text-right w-32">Tiền ra (−)</th>
              <th class="px-4 py-3 text-center w-28">Trạng thái</th>
              <th class="px-4 py-3 text-left w-24">Phiếu KT</th>
              <th v-if="can('accounting.manage')" class="px-4 py-3 w-24"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="tx in transactions.data" :key="tx.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 text-xs text-gray-500">{{ tx.transaction_date }}</td>
              <td class="px-4 py-3 text-gray-800">{{ tx.description }}</td>
              <td class="px-4 py-3 text-xs font-mono text-gray-500">{{ tx.reference || '—' }}</td>
              <td class="px-4 py-3 text-right text-green-600 font-medium">
                {{ tx.credit > 0 ? formatVnd(tx.credit) : '' }}
              </td>
              <td class="px-4 py-3 text-right text-red-600 font-medium">
                {{ tx.debit > 0 ? formatVnd(tx.debit) : '' }}
              </td>
              <td class="px-4 py-3 text-center">
                <span :class="`badge-${tx.status_color}`">{{ tx.status_label }}</span>
              </td>
              <td class="px-4 py-3">
                <span v-if="tx.journal_entry_code" class="text-xs font-mono text-primary-600">{{ tx.journal_entry_code }}</span>
                <span v-else class="text-xs text-gray-400">—</span>
              </td>
              <td v-if="can('accounting.manage')" class="px-4 py-3">
                <!-- Reconcile modal trigger -->
                <template v-if="tx.status === 'pending'">
                  <button @click="openReconcile(tx)" class="text-xs text-blue-600 hover:underline">Đối chiếu</button>
                </template>
                <template v-else>
                  <form @submit.prevent="unreconcile(tx)">
                    <button type="submit" class="text-xs text-red-500 hover:underline">Hủy ĐC</button>
                  </form>
                </template>
              </td>
            </tr>
            <tr v-if="transactions.data.length === 0">
              <td :colspan="can('accounting.manage') ? 8 : 7" class="px-4 py-10 text-center text-gray-400">
                Chưa có giao dịch
              </td>
            </tr>
          </tbody>
        </table>
        <Pagination :links="transactions.links" class="px-4 py-3" />
      </div>

      <!-- Reconcile modal -->
      <div v-if="reconcileTarget" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
          <h3 class="text-lg font-bold text-gray-900 mb-3">Đối chiếu giao dịch</h3>
          <div class="bg-gray-50 rounded-lg p-3 mb-4 text-sm">
            <p class="font-medium">{{ reconcileTarget.description }}</p>
            <p class="text-gray-500 text-xs mt-1">
              {{ reconcileTarget.transaction_date }} |
              <span v-if="reconcileTarget.credit > 0" class="text-green-600">+{{ formatVnd(reconcileTarget.credit) }}</span>
              <span v-else class="text-red-600">-{{ formatVnd(reconcileTarget.debit) }}</span>
            </p>
          </div>
          <div class="mb-4">
            <label class="form-label">Chọn Phiếu kế toán (TK 112)</label>
            <select v-model="reconcileForm.journal_entry_id" class="form-input">
              <option :value="null">-- Chọn phiếu KT --</option>
              <option v-for="je in pendingJEs" :key="je.id" :value="je.id">
                {{ je.code }} — {{ je.entry_date }} — {{ je.description?.substring(0, 50) }}
              </option>
            </select>
          </div>
          <div class="flex gap-3">
            <button @click="submitReconcile" :disabled="!reconcileForm.journal_entry_id || reconcileForm.processing"
              class="btn-primary flex-1">Xác nhận đối chiếu</button>
            <button @click="reconcileTarget = null" class="btn-secondary">Huỷ</button>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';

const { hasPermission: can } = usePermission();

const props = defineProps({
  bankAccount:  Object,
  transactions: Object,
  pendingJEs:   Array,
  filters:      Object,
  statuses:     Array,
});

const filters = ref({
  status:    props.filters.status    ?? '',
  date_from: props.filters.date_from ?? '',
  date_to:   props.filters.date_to   ?? '',
});

const addForm = useForm({
  transaction_date: new Date().toISOString().split('T')[0],
  description:      '',
  reference:        '',
  debit:            0,
  credit:           0,
});

const reconcileTarget = ref(null);
const reconcileForm   = useForm({ journal_entry_id: null });

function applyFilters() {
  router.get(route('accounting.bank-accounts.transactions.index', props.bankAccount.id), filters.value, { preserveState: true });
}

function submitAdd() {
  addForm.post(route('accounting.bank-accounts.transactions.store', props.bankAccount.id), {
    onSuccess: () => addForm.reset('description', 'debit', 'credit', 'reference'),
  });
}

function openReconcile(tx) {
  reconcileTarget.value = tx;
  reconcileForm.journal_entry_id = null;
}

function submitReconcile() {
  reconcileForm.post(
    route('accounting.bank-accounts.transactions.reconcile', [props.bankAccount.id, reconcileTarget.value.id]),
    { onSuccess: () => { reconcileTarget.value = null; } }
  );
}

function unreconcile(tx) {
  router.post(route('accounting.bank-accounts.transactions.unreconcile', [props.bankAccount.id, tx.id]));
}

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫';
}
</script>
