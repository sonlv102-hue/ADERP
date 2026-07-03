<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Chi phí trả trước</h1>
          <p class="text-sm text-gray-500 mt-0.5">TK 142 (ngắn hạn) / TK 242 (dài hạn)</p>
        </div>
        <div class="flex gap-2 flex-wrap">
          <Link :href="route('accounting.prepaid-expenses.reports.gl-reconcile')"
            class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50">
            Đối soát GL
          </Link>
          <Link v-if="can('accounting.manage')" :href="route('accounting.prepaid-expenses.opening-balance.create')"
            class="border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50">
            Số dư đầu kỳ
          </Link>
          <Link v-if="can('accounting.manage')" :href="route('accounting.prepaid-expenses.create')"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Thêm mới
          </Link>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @change="applyFilters" placeholder="Tìm mã, diễn giải..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="status" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option value="active">Đang phân bổ</option>
          <option value="fully_amortized">Đã phân bổ hết</option>
          <option value="cancelled">Đã hủy</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Diễn giải</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">TK</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Đã phân bổ</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Còn lại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kỳ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Phân bổ</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="e in expenses.data" :key="e.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-700">{{ e.code }}</td>
              <td class="px-5 py-3 text-gray-800">
                {{ e.description }}
                <span v-if="e.is_opening_balance" class="ml-1 text-xs text-gray-400">(Số dư đầu kỳ)</span>
              </td>
              <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ e.account_code }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-800">{{ fmt(e.total_amount) }}</td>
              <td class="px-5 py-3 text-right text-green-700">{{ fmt(e.amortized_amount) }}</td>
              <td class="px-5 py-3 text-right font-semibold"
                :class="e.remaining_amount >= 0 ? 'text-blue-700' : 'text-orange-600'">
                {{ fmt(e.remaining_amount) }}
              </td>
              <td class="px-5 py-3 text-xs text-gray-500">{{ e.start_date }} → {{ e.end_date }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="e.status_color">{{ e.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="allocStatusClass(e.allocation_status)">
                  {{ allocStatusLabel(e.allocation_status) }}
                </span>
              </td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <button v-if="can('accounting.manage') && e.allocation_status === 'active'"
                  @click="openPause(e)" class="text-yellow-700 hover:text-yellow-900 text-xs font-medium mr-3">Tạm dừng</button>
                <button v-if="can('accounting.manage') && e.allocation_status === 'paused'"
                  @click="resume(e)" class="text-green-700 hover:text-green-900 text-xs font-medium mr-3">Tiếp tục</button>
                <Link :href="route('accounting.prepaid-expenses.show', e.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium text-sm">Xem</Link>
              </td>
            </tr>
            <tr v-if="!expenses.data?.length">
              <td colspan="10" class="px-5 py-10 text-center text-gray-400">Chưa có chi phí trả trước nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="expenses.links" :meta="expenses.meta" />
    </div>

    <!-- Modal Tạm dừng -->
    <Modal :show="!!pauseTarget" max-width="md" @close="pauseTarget = null">
      <template #title>Tạm dừng phân bổ — {{ pauseTarget?.code }}</template>
      <div class="space-y-3">
        <p class="text-sm text-gray-600">Chi phí <strong>{{ pauseTarget?.description }}</strong> sẽ không được phân bổ ở các kỳ tạm dừng.
          Số dư còn lại được giữ nguyên.</p>
        <FormField label="Lý do tạm dừng" required :error="pauseForm.errors.reason">
          <textarea v-model="pauseForm.reason" rows="2" class="erp-input" />
        </FormField>
      </div>
      <template #footer>
        <button @click="pauseTarget = null" class="erp-btn-secondary">Hủy</button>
        <button @click="submitPause" :disabled="pauseForm.processing" class="erp-btn-primary">Xác nhận tạm dừng</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import Modal from '@/Components/Shared/Modal.vue';
import FormField from '@/Components/Shared/FormField.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ expenses: Object, filters: Object });
const { hasPermission: can } = usePermission();
const { formatVnd: fmt } = useCurrency();

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');

function applyFilters() {
  router.get(route('accounting.prepaid-expenses.index'),
    { search: search.value, status: status.value },
    { preserveState: true });
}

function allocStatusLabel(s) {
  return { active: 'Đang phân bổ', paused: 'Tạm dừng', completed: 'Đã hoàn thành', not_started: 'Chưa bắt đầu' }[s] ?? (s || 'Đang phân bổ');
}
function allocStatusClass(s) {
  return {
    active: 'bg-green-100 text-green-700', paused: 'bg-yellow-100 text-yellow-700',
    completed: 'bg-blue-100 text-blue-700', not_started: 'bg-gray-100 text-gray-500',
  }[s] || 'bg-green-100 text-green-700';
}

const pauseTarget = ref(null);
const pauseForm = useForm({ reason: '' });
function openPause(e) { pauseTarget.value = e; pauseForm.reset('reason'); }
function submitPause() {
  pauseForm.post(route('accounting.prepaid-expenses.pause', pauseTarget.value.id), {
    onSuccess: () => { pauseTarget.value = null; },
  });
}

function resume(e) {
  router.post(route('accounting.prepaid-expenses.resume', e.id));
}
</script>
