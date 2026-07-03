<template>
  <AppLayout>
    <div class="max-w-6xl">
      <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <h1 class="text-2xl font-bold text-gray-900">Bảng phân bổ CCDC</h1>
        <div class="flex gap-2 flex-wrap">
          <select v-model="statusFilter" class="erp-input w-44" @change="apply">
            <option value="">— Tất cả trạng thái —</option>
            <option value="active">Đang phân bổ</option>
            <option value="paused">Tạm dừng</option>
            <option value="completed">Đã hoàn thành</option>
            <option value="not_started">Chưa bắt đầu</option>
          </select>
          <input v-model="toolFilter" type="text" placeholder="Tìm mã/tên CCDC..."
            class="erp-input w-52" @keyup.enter="apply" />
        </div>
      </div>

      <div v-for="tool in props.schedule" :key="tool.id" class="bg-white rounded-xl border border-gray-200 mb-5">
        <div class="px-5 py-3 border-b flex items-center justify-between flex-wrap gap-2">
          <div>
            <span class="font-mono text-sm font-bold text-primary-700 mr-2">{{ tool.code }}</span>
            <span class="font-medium">{{ tool.name }}</span>
            <span class="ml-3 text-xs text-gray-500">{{ tool.department || '' }}</span>
            <span class="ml-3 px-2 py-0.5 rounded-full text-xs font-medium" :class="toolStatusClass(tool.allocation_status)">
              {{ toolStatusLabel(tool.allocation_status) }}
            </span>
          </div>
          <div class="flex items-center gap-3">
            <div class="text-sm text-gray-600 text-right">
              Nguyên giá: <span class="font-mono font-semibold">{{ formatVnd(tool.original_cost) }}</span>
              · TK Nợ: <span class="font-mono">{{ tool.expense_account_code }}</span>
            </div>
            <button v-if="can('ccdc.allocate') && tool.allocation_status === 'active'"
              @click="openPause(tool)" class="erp-btn-secondary erp-btn-sm">Tạm dừng</button>
            <button v-if="can('ccdc.allocate') && tool.allocation_status === 'paused'"
              @click="openResume(tool)" class="erp-btn-primary erp-btn-sm">Tiếp tục</button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-xs">
            <thead class="bg-gray-50 border-b text-gray-500">
              <tr>
                <th class="px-3 py-2 text-left">Kỳ</th>
                <th class="px-3 py-2 text-right">Số tiền PB</th>
                <th class="px-3 py-2 text-right">Lũy kế</th>
                <th class="px-3 py-2 text-right">Còn lại</th>
                <th class="px-3 py-2 text-center">Trạng thái</th>
                <th class="px-3 py-2 text-center">BT</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <tr v-for="row in tool.allocations" :key="row.id"
                :class="row.period === currentPeriod ? 'bg-yellow-50' : 'hover:bg-gray-50'">
                <td class="px-3 py-2 font-mono">{{ row.period }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatVnd(row.amount) }}</td>
                <td class="px-3 py-2 text-right font-mono text-blue-700">{{ formatVnd(row.accumulated) }}</td>
                <td class="px-3 py-2 text-right font-mono text-orange-600">{{ formatVnd(row.remaining) }}</td>
                <td class="px-3 py-2 text-center">
                  <span :class="allocationStatusClass(row.status)" class="px-1.5 py-0.5 rounded text-xs">
                    {{ allocationStatusLabel(row.status) }}
                  </span>
                </td>
                <td class="px-3 py-2 text-center">
                  <Link v-if="row.journal_entry_id"
                    :href="route('accounting.journal-entries.show', row.journal_entry_id)"
                    class="text-primary-600 hover:underline">#{{ row.journal_entry_id }}</Link>
                  <span v-else class="text-gray-300">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="!props.schedule.length" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-400">
        Không có CCDC nào đang phân bổ.
      </div>
    </div>

    <!-- Modal Tạm dừng -->
    <Modal :show="!!pauseTarget" max-width="md" @close="pauseTarget = null">
      <template #title>Tạm dừng phân bổ — {{ pauseTarget?.code }}</template>
      <div class="space-y-3">
        <p class="text-sm text-gray-600">CCDC <strong>{{ pauseTarget?.name }}</strong> sẽ không được phân bổ ở các kỳ tạm dừng.
          Số dư còn lại và số kỳ còn lại được giữ nguyên.</p>
        <FormField label="Lý do tạm dừng" required :error="pauseForm.errors.reason">
          <textarea v-model="pauseForm.reason" rows="2" class="erp-input" placeholder="Ví dụ: CCDC tạm thời không sử dụng" />
        </FormField>
      </div>
      <template #footer>
        <button @click="pauseTarget = null" class="erp-btn-secondary">Hủy</button>
        <button @click="submitPause" :disabled="pauseForm.processing" class="erp-btn-primary">Xác nhận tạm dừng</button>
      </template>
    </Modal>

    <!-- Modal Tiếp tục -->
    <Modal :show="!!resumeTarget" max-width="md" @close="resumeTarget = null">
      <template #title>Tiếp tục phân bổ — {{ resumeTarget?.code }}</template>
      <div class="space-y-2 text-sm text-gray-700">
        <p>Xác nhận tiếp tục phân bổ cho CCDC <strong>{{ resumeTarget?.name }}</strong>.</p>
        <p>Số dư còn lại và số kỳ còn lại được giữ nguyên như trước khi tạm dừng. Phân bổ sẽ tiếp tục
          từ kỳ chưa ghi sổ gần nhất.</p>
      </div>
      <template #footer>
        <button @click="resumeTarget = null" class="erp-btn-secondary">Hủy</button>
        <button @click="submitResume" :disabled="resumeForm.processing" class="erp-btn-primary">Xác nhận tiếp tục</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Modal from '@/Components/Shared/Modal.vue';
import FormField from '@/Components/Shared/FormField.vue';
import { useCurrency } from '@/composables/useCurrency';
import { usePermission } from '@/composables/usePermission';

const { formatVnd } = useCurrency();
const { hasPermission: can } = usePermission();
const props = defineProps({ schedule: Array, currentPeriod: String, filters: Object });

const toolFilter   = ref(props.filters?.tool || '');
const statusFilter = ref(props.filters?.allocation_status || '');

function apply() {
  router.get(route('accounting.small-tools.reports.allocation-schedule'),
    { tool: toolFilter.value, allocation_status: statusFilter.value },
    { preserveState: true });
}

function allocationStatusLabel(s) {
  return { pending: 'Chờ', posted: 'Đã hạch toán', reversed: 'Đã đảo', cancelled: 'Đã hủy' }[s] ?? s;
}
function allocationStatusClass(s) {
  return { pending: 'bg-yellow-100 text-yellow-700', posted: 'bg-green-100 text-green-700', reversed: 'bg-blue-100 text-blue-700', cancelled: 'bg-gray-100 text-gray-500' }[s];
}

function toolStatusLabel(s) {
  return { active: 'Đang phân bổ', paused: 'Tạm dừng', completed: 'Đã hoàn thành', not_started: 'Chưa bắt đầu' }[s] ?? (s || 'Đang phân bổ');
}
function toolStatusClass(s) {
  return {
    active: 'bg-green-100 text-green-700', paused: 'bg-yellow-100 text-yellow-700',
    completed: 'bg-blue-100 text-blue-700', not_started: 'bg-gray-100 text-gray-500',
  }[s] || 'bg-green-100 text-green-700';
}

// Tạm dừng
const pauseTarget = ref(null);
const pauseForm = useForm({ reason: '' });
function openPause(tool) { pauseTarget.value = tool; pauseForm.reset('reason'); }
function submitPause() {
  pauseForm.post(route('accounting.small-tools.allocation.pause', pauseTarget.value.id), {
    onSuccess: () => { pauseTarget.value = null; },
  });
}

// Tiếp tục
const resumeTarget = ref(null);
const resumeForm = useForm({});
function openResume(tool) { resumeTarget.value = tool; }
function submitResume() {
  resumeForm.post(route('accounting.small-tools.allocation.resume', resumeTarget.value.id), {
    onSuccess: () => { resumeTarget.value = null; },
  });
}
</script>
