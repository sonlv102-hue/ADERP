<template>
  <AppLayout>
    <div class="max-w-5xl">
      <!-- Header -->
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.small-tools.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ tool.code }} — {{ tool.name }}</h1>
        <span class="px-2 py-0.5 rounded-full text-xs font-medium"
          :class="`bg-${tool.status_color}-100 text-${tool.status_color}-700`">
          {{ tool.status_label }}
        </span>
      </div>

      <!-- Actions -->
      <div class="flex gap-2 mb-6 flex-wrap">
        <Link v-if="can('ccdc.manage') && tool.status === 'draft'"
          :href="route('accounting.small-tools.edit', tool.id)"
          class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50">
          Sửa
        </Link>
        <button v-if="can('ccdc.manage') && tool.status === 'draft' && tool.acquisition_type === 'direct'"
          @click="confirm" class="px-4 py-2 bg-primary-600 text-white rounded-lg text-sm hover:bg-primary-700">
          Ghi nhận bút toán
        </button>
        <Link v-if="can('ccdc.manage') && tool.status === 'in_stock'"
          :href="route('accounting.small-tools.receipts.create')"
          class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50">
          Tạo phiếu xuất dùng
        </Link>
        <Link v-if="can('ccdc.manage') && ['in_stock','in_use','allocating'].includes(tool.status)"
          :href="route('accounting.small-tools.transfers.create', tool.id)"
          class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50">
          Điều chuyển
        </Link>
        <Link v-if="can('ccdc.dispose') && ['in_stock','in_use','allocating'].includes(tool.status)"
          :href="route('accounting.small-tools.disposals.create', tool.id)"
          class="px-4 py-2 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm hover:bg-red-100">
          Hỏng/Mất/Thanh lý
        </Link>
      </div>

      <div class="grid grid-cols-3 gap-5">
        <!-- Left: Info cards -->
        <div class="col-span-2 space-y-5">
          <!-- Core info -->
          <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-sm font-semibold text-gray-700 mb-4">Thông tin CCDC</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-gray-500">Nhóm</dt><dd class="font-medium">{{ tool.category_name || '—' }}</dd></div>
              <div><dt class="text-gray-500">Đơn vị / Số lượng</dt><dd class="font-medium">{{ tool.quantity }} {{ tool.unit }}</dd></div>
              <div><dt class="text-gray-500">Ngày mua</dt><dd class="font-medium">{{ tool.purchase_date || '—' }}</dd></div>
              <div><dt class="text-gray-500">Ngày sử dụng</dt><dd class="font-medium">{{ tool.in_service_date || '—' }}</dd></div>
              <div><dt class="text-gray-500">Bộ phận</dt><dd class="font-medium">{{ tool.department || '—' }}</dd></div>
              <div><dt class="text-gray-500">Nhân viên</dt><dd class="font-medium">{{ tool.responsible_employee_name || '—' }}</dd></div>
              <div><dt class="text-gray-500">Kho</dt><dd class="font-medium">{{ tool.warehouse_name || '—' }}</dd></div>
              <div><dt class="text-gray-500">Dự án</dt><dd class="font-medium">{{ tool.project_name || '—' }}</dd></div>
              <div><dt class="text-gray-500">Nhà cung cấp</dt><dd class="font-medium">{{ tool.supplier_name || '—' }}</dd></div>
              <div><dt class="text-gray-500">Luồng nghiệp vụ</dt><dd class="font-medium">{{ acquisitionLabel }}</dd></div>
              <div><dt class="text-gray-500">Ghi nhận chi phí</dt><dd class="font-medium">{{ recognitionLabel }}</dd></div>
              <div v-if="tool.allocation_periods">
                <dt class="text-gray-500">Số kỳ phân bổ</dt>
                <dd class="font-medium">{{ tool.periods_allocated }}/{{ tool.allocation_periods }} kỳ</dd>
              </div>
            </dl>
          </div>

          <!-- Accounts -->
          <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Tài khoản kế toán</h2>
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div class="flex justify-between">
                <span class="text-gray-500">TK kho (1531)</span>
                <span class="font-mono font-medium">{{ tool.stock_account_code }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">TK chờ phân bổ (2422)</span>
                <span class="font-mono font-medium">{{ tool.pending_account_code }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">TK chi phí</span>
                <span class="font-mono font-medium">{{ tool.expense_account_code }}</span>
              </div>
            </div>
          </div>

          <!-- Allocation schedule -->
          <div v-if="allocations.length" class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
            <div class="px-5 py-4 border-b border-gray-200 flex justify-between items-center">
              <h2 class="text-sm font-semibold text-gray-800">Lịch phân bổ</h2>
              <Link v-if="can('ccdc.allocate')"
                :href="route('accounting.small-tools.allocations.index', { period: currentPeriod })"
                class="text-xs text-primary-600 hover:text-primary-800">Phân bổ tháng →</Link>
            </div>
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-2 text-left text-gray-600">Kỳ</th>
                  <th class="px-4 py-2 text-right text-gray-600">Phân bổ kỳ này</th>
                  <th class="px-4 py-2 text-right text-gray-600">Lũy kế</th>
                  <th class="px-4 py-2 text-right text-gray-600">Còn lại</th>
                  <th class="px-4 py-2 text-center text-gray-600">TT</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="a in allocations" :key="a.id" class="hover:bg-gray-50">
                  <td class="px-4 py-2 font-mono">{{ a.period }}</td>
                  <td class="px-4 py-2 text-right font-mono">{{ formatVnd(a.amount) }}</td>
                  <td class="px-4 py-2 text-right font-mono text-gray-500">{{ formatVnd(a.accumulated_before + a.amount) }}</td>
                  <td class="px-4 py-2 text-right font-mono">{{ formatVnd(a.remaining_after) }}</td>
                  <td class="px-4 py-2 text-center">
                    <span :class="statusClass(a.status)" class="px-1.5 py-0.5 rounded text-xs font-medium">
                      {{ statusLabel(a.status) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Transfers -->
          <div v-if="transfers.length" class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
            <div class="px-5 py-4 border-b border-gray-200">
              <h2 class="text-sm font-semibold text-gray-800">Lịch sử điều chuyển</h2>
            </div>
            <div class="divide-y divide-gray-100">
              <div v-for="t in transfers" :key="t.id" class="px-5 py-3 text-sm">
                <div class="flex justify-between">
                  <span class="font-mono text-xs text-gray-500">{{ t.code }}</span>
                  <span class="text-gray-500">{{ t.transfer_date }}</span>
                </div>
                <p class="mt-1 text-gray-700">
                  {{ t.from_department || t.from_employee }} → {{ t.to_department || t.to_employee }}
                  <span v-if="t.to_project" class="text-gray-500 ml-2">({{ t.to_project }})</span>
                </p>
                <p v-if="t.reason" class="text-xs text-gray-400 mt-0.5">{{ t.reason }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Summary card -->
        <div class="space-y-4">
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Tóm tắt giá trị</h3>
            <div class="space-y-3 text-sm">
              <div class="flex justify-between">
                <span class="text-gray-500">Nguyên giá</span>
                <span class="font-mono font-semibold">{{ formatVnd(tool.original_cost) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500">Tiền VAT</span>
                <span class="font-mono text-gray-600">{{ formatVnd(tool.vat_amount) }}</span>
              </div>
              <div class="flex justify-between border-t pt-2">
                <span class="text-gray-500">Tổng tiền</span>
                <span class="font-mono font-bold">{{ formatVnd(tool.total_cost) }}</span>
              </div>
              <div class="flex justify-between border-t pt-2">
                <span class="text-gray-500">Đã phân bổ</span>
                <span class="font-mono text-green-600">{{ formatVnd(tool.total_allocated) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-500 font-semibold">Còn lại</span>
                <span class="font-mono font-bold" :class="tool.total_remaining > 0 ? 'text-orange-600' : 'text-gray-400'">
                  {{ formatVnd(tool.total_remaining) }}
                </span>
              </div>
            </div>
          </div>

          <!-- Disposals -->
          <div v-if="disposals.length" class="bg-red-50 rounded-xl border border-red-200 p-5">
            <h3 class="text-sm font-semibold text-red-700 mb-3">Lịch sử xử lý</h3>
            <div v-for="d in disposals" :key="d.id" class="text-xs text-red-600 mb-2">
              <p class="font-medium">{{ d.type_label }} — {{ d.disposal_date }}</p>
              <p class="text-red-500">{{ d.reason }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();

const props = defineProps({ tool: Object, allocations: Array, transfers: Array, disposals: Array, statuses: Array });

const currentPeriod = new Date().toISOString().slice(0, 7);

const acquisitionLabel = { stock: 'Nhập kho (1531)', direct: 'Dùng ngay' }[props.tool.acquisition_type] ?? '';
const recognitionLabel  = { immediate: 'Chi phí một lần', allocation: 'Phân bổ nhiều kỳ' }[props.tool.recognition_method] ?? '';

function confirm() {
  router.post(route('accounting.small-tools.confirm', props.tool.id));
}

function statusClass(status) {
  return {
    pending:  'bg-yellow-100 text-yellow-700',
    posted:   'bg-green-100 text-green-700',
    reversed: 'bg-red-100 text-red-700',
    cancelled: 'bg-gray-100 text-gray-500',
  }[status] || 'bg-gray-100 text-gray-500';
}

function statusLabel(status) {
  return { pending: 'Chờ', posted: 'Đã post', reversed: 'Đã đảo', cancelled: 'Hủy' }[status] || status;
}
</script>
