<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.fixed-assets.index')" class="text-slate-400 hover:text-slate-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <div class="flex items-center gap-2 flex-wrap">
              <h1 class="text-2xl font-bold text-slate-900">{{ asset.name }}</h1>
              <span class="erp-badge" :class="badgeClass(asset.status_color)">{{ asset.status_label }}</span>
            </div>
            <p class="text-sm text-slate-500 font-mono">{{ asset.code }} · {{ asset.category_name }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap" v-if="can('accounting.manage')">
          <!-- Place in service -->
          <button v-if="asset.status === 'pending_use'" @click="showPlaceInService = true"
            class="erp-btn-secondary text-green-700 border-green-300 hover:bg-green-50">
            Đưa vào sử dụng
          </button>
          <!-- Suspend/Resume -->
          <button v-if="asset.status === 'active'" @click="showSuspend = true"
            class="erp-btn-secondary text-amber-700 border-amber-300 hover:bg-amber-50">
            Tạm dừng KH
          </button>
          <button v-if="asset.status === 'suspended'" @click="doResume"
            class="erp-btn-secondary text-green-700 border-green-300 hover:bg-green-50">
            Tiếp tục KH
          </button>
          <!-- Transfer -->
          <button v-if="['active','suspended'].includes(asset.status)" @click="showTransfer = true"
            class="erp-btn-secondary">Điều chuyển</button>
          <!-- Edit -->
          <Link :href="route('accounting.fixed-assets.edit', asset.id)" class="erp-btn-secondary">Sửa</Link>
          <!-- Dispose -->
          <Link v-if="!['disposed','written_off'].includes(asset.status)"
            :href="route('accounting.fixed-assets.disposals.create', asset.id)"
            class="erp-btn-secondary text-red-700 border-red-300 hover:bg-red-50">
            Thanh lý
          </Link>
        </div>
      </div>

      <!-- KPI summary -->
      <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase tracking-wide font-semibold mb-1">Nguyên giá</p>
          <p class="text-2xl font-bold text-slate-900">{{ fmt(asset.acquisition_cost) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase tracking-wide font-semibold mb-1">Hao mòn lũy kế</p>
          <p class="text-2xl font-bold text-red-600">{{ fmt(asset.accumulated_depreciation) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase tracking-wide font-semibold mb-1">Giá trị còn lại</p>
          <p class="text-2xl font-bold text-indigo-700">{{ fmt(asset.net_book_value) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
          <p class="text-xs text-slate-500 uppercase tracking-wide font-semibold mb-1">KH tháng</p>
          <p class="text-2xl font-bold text-slate-700">{{ fmt(asset.monthly_depreciation) }}</p>
          <p class="text-xs text-slate-400">Còn lại {{ asset.months_remaining || '—' }} tháng</p>
        </div>
      </div>

      <!-- Tabs -->
      <div class="border-b border-slate-200">
        <nav class="flex gap-1">
          <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
            class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors"
            :class="activeTab === tab.id ? 'bg-white border border-b-white border-slate-200 text-indigo-700 -mb-px' : 'text-slate-500 hover:text-slate-700'">
            {{ tab.label }}
          </button>
        </nav>
      </div>

      <!-- Tab: Thông tin chung -->
      <div v-show="activeTab === 'info'" class="bg-white rounded-xl border border-slate-200 p-6">
        <dl class="grid grid-cols-3 gap-x-8 gap-y-4 text-sm">
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Loại tài sản</dt><dd class="mt-1 text-slate-900">{{ assetTypeLabel }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Nguồn hình thành</dt><dd class="mt-1 text-slate-900">{{ sourceTypeLabel }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Số serial</dt><dd class="mt-1 font-mono text-slate-900">{{ asset.serial_number || '—' }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Nhà cung cấp</dt><dd class="mt-1">{{ asset.supplier_name || '—' }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Bộ phận</dt><dd class="mt-1">{{ asset.department || '—' }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Vị trí</dt><dd class="mt-1">{{ asset.location || '—' }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Ngày mua</dt><dd class="mt-1">{{ asset.acquisition_date }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Ngày ghi tăng</dt><dd class="mt-1">{{ asset.recognition_date || '—' }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Ngày sử dụng</dt><dd class="mt-1">{{ asset.placed_in_service_date || '—' }}</dd></div>
        </dl>
      </div>

      <!-- Tab: Thông tin kế toán -->
      <div v-show="activeTab === 'accounting'" class="bg-white rounded-xl border border-slate-200 p-6">
        <dl class="grid grid-cols-2 gap-x-8 gap-y-4 text-sm">
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">TK nguyên giá</dt><dd class="mt-1 font-mono text-indigo-700">{{ asset.original_cost_account_code }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">TK hao mòn</dt><dd class="mt-1 font-mono text-indigo-700">{{ asset.accumulated_dep_account_code }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">TK chi phí KH</dt><dd class="mt-1 font-mono text-indigo-700">{{ asset.depreciation_expense_account_code }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">TK thanh toán</dt><dd class="mt-1 font-mono text-indigo-700">{{ asset.payable_account_code }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Phương pháp KH</dt><dd class="mt-1">Đường thẳng</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Thời gian KH</dt><dd class="mt-1">{{ asset.useful_life_months }} tháng ({{ (asset.useful_life_months / 12).toFixed(1) }} năm)</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Bắt đầu KH</dt><dd class="mt-1">{{ asset.depreciation_start_date || '—' }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Kết thúc KH</dt><dd class="mt-1">{{ asset.depreciation_end_date || '—' }}</dd></div>
          <div><dt class="text-slate-500 text-xs uppercase font-semibold">Bút toán ghi tăng</dt>
            <dd class="mt-1">
              <Link v-if="asset.acquisition_journal_entry_id"
                :href="route('accounting.journal-entries.show', asset.acquisition_journal_entry_id)"
                class="text-indigo-600 hover:underline text-xs">Xem bút toán</Link>
              <span v-else class="text-slate-400">Chưa có</span>
            </dd>
          </div>
        </dl>
      </div>

      <!-- Tab: Lịch khấu hao -->
      <div v-show="activeTab === 'schedule'" class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <div class="p-4 border-b border-slate-100 flex justify-between items-center">
          <h3 class="font-semibold text-slate-800">Lịch khấu hao</h3>
          <span class="text-xs text-slate-400">{{ schedule.length }} kỳ</span>
        </div>
        <div class="overflow-y-auto max-h-96">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 sticky top-0">
              <tr>
                <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Kỳ</th>
                <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500">KH tháng</th>
                <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500">Hao mòn LK</th>
                <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500">Giá trị còn lại</th>
                <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Trạng thái</th>
                <th class="px-4 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in schedule" :key="row.period" :class="!row.posted ? 'opacity-50' : ''">
                <td class="px-4 py-2 font-mono text-slate-700">{{ row.period }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ fmt(row.amount) }}</td>
                <td class="px-4 py-2 text-right font-mono text-slate-500">{{ fmt(row.accumulated_after) }}</td>
                <td class="px-4 py-2 text-right font-mono font-semibold text-indigo-700">{{ fmt(row.net_book_value_after) }}</td>
                <td class="px-4 py-2">
                  <span class="erp-badge" :class="row.posted ? 'erp-badge-green' : 'erp-badge-gray'">
                    {{ row.status === 'reversed' ? 'Đã hủy' : row.posted ? 'Đã ghi sổ' : 'Dự kiến' }}
                  </span>
                </td>
                <td class="px-4 py-2">
                  <Link v-if="row.journal_entry_id"
                    :href="route('accounting.journal-entries.show', row.journal_entry_id)"
                    class="text-xs text-indigo-600 hover:underline">BT</Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Tab: Lịch sử bút toán -->
      <div v-show="activeTab === 'journals'" class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <div class="p-4 border-b border-slate-100">
          <h3 class="font-semibold text-slate-800">Lịch sử khấu hao đã ghi sổ</h3>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Kỳ</th>
              <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500">Số tiền</th>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Trạng thái</th>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Bút toán</th>
              <th class="px-4 py-2" v-if="can('accounting.manage')" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="d in depreciations.data" :key="d.id">
              <td class="px-4 py-2 font-mono">{{ d.period }}</td>
              <td class="px-4 py-2 text-right font-mono">{{ fmt(d.amount) }}</td>
              <td class="px-4 py-2">
                <span class="erp-badge" :class="d.status === 'posted' ? 'erp-badge-green' : d.status === 'reversed' ? 'erp-badge-red' : 'erp-badge-yellow'">
                  {{ { posted: 'Đã ghi sổ', reversed: 'Đã hủy', planned: 'Chờ duyệt', adjusted: 'Đã điều chỉnh' }[d.status] || d.status }}
                </span>
              </td>
              <td class="px-4 py-2">
                <Link v-if="d.journal_entry_id" :href="route('accounting.journal-entries.show', d.journal_entry_id)"
                  class="text-xs text-indigo-600 hover:underline">Xem BT</Link>
                <span v-else class="text-slate-400 text-xs">—</span>
              </td>
              <td class="px-4 py-2" v-if="can('accounting.manage')">
                <button v-if="d.status === 'posted'" @click="confirmReverse(d)"
                  class="text-xs text-red-500 hover:text-red-700">Hủy</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Tab: Điều chuyển -->
      <div v-show="activeTab === 'movements'" class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <div class="p-4 border-b border-slate-100">
          <h3 class="font-semibold text-slate-800">Lịch sử điều chuyển và thay đổi</h3>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Ngày</th>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Loại</th>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Từ bộ phận</th>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Đến bộ phận</th>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Ghi chú</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="m in movements" :key="m.id">
              <td class="px-4 py-2 text-slate-600">{{ m.movement_date }}</td>
              <td class="px-4 py-2">
                <span class="erp-badge erp-badge-gray">{{ movementLabel(m.movement_type) }}</span>
              </td>
              <td class="px-4 py-2 text-slate-600">{{ m.from_department || '—' }}</td>
              <td class="px-4 py-2 text-slate-600">{{ m.to_department || '—' }}</td>
              <td class="px-4 py-2 text-slate-500 text-xs">{{ m.notes }}</td>
            </tr>
            <tr v-if="movements.length === 0">
              <td colspan="5" class="px-4 py-8 text-center text-slate-400">Chưa có lịch sử.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Tab: Sửa chữa -->
      <div v-show="activeTab === 'repairs'" class="space-y-3">
        <div class="flex justify-end">
          <Link v-if="can('accounting.manage')" :href="route('accounting.fixed-assets.repairs.create', asset.id)" class="erp-btn-primary">
            + Ghi nhận sửa chữa
          </Link>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
              <tr>
                <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Ngày</th>
                <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Loại</th>
                <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Mô tả</th>
                <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500">Số tiền</th>
                <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Hạch toán</th>
                <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Trạng thái</th>
                <th class="px-4 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="r in repairs" :key="r.id">
                <td class="px-4 py-2 text-slate-600">{{ r.repair_date }}</td>
                <td class="px-4 py-2"><span class="erp-badge erp-badge-gray">{{ repairTypeLabel(r.repair_type) }}</span></td>
                <td class="px-4 py-2 text-slate-700">{{ r.description }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ fmt(r.amount) }}</td>
                <td class="px-4 py-2 text-xs text-slate-500">{{ treatmentLabel(r.accounting_treatment) }}</td>
                <td class="px-4 py-2">
                  <span class="erp-badge" :class="r.status === 'posted' ? 'erp-badge-green' : 'erp-badge-yellow'">
                    {{ r.status === 'posted' ? 'Ghi sổ' : 'Nháp' }}
                  </span>
                </td>
                <td class="px-4 py-2">
                  <Link v-if="r.journal_entry_id" :href="route('accounting.journal-entries.show', r.journal_entry_id)"
                    class="text-xs text-indigo-600 hover:underline">BT</Link>
                </td>
              </tr>
              <tr v-if="repairs.length === 0">
                <td colspan="7" class="px-4 py-8 text-center text-slate-400">Chưa có sửa chữa nào.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Tab: Thanh lý -->
      <div v-show="activeTab === 'disposals'" class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <div class="p-4 border-b border-slate-100">
          <h3 class="font-semibold text-slate-800">Thanh lý / nhượng bán</h3>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Ngày</th>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Loại</th>
              <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500">Giá bán</th>
              <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500">Lãi/lỗ</th>
              <th class="text-left px-4 py-2 text-xs font-semibold text-slate-500">Trạng thái</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="d in disposals" :key="d.id">
              <td class="px-4 py-2 text-slate-600">{{ d.disposal_date }}</td>
              <td class="px-4 py-2"><span class="erp-badge erp-badge-gray">{{ disposalTypeLabel(d.disposal_type) }}</span></td>
              <td class="px-4 py-2 text-right font-mono">{{ fmt(d.selling_price) }}</td>
              <td class="px-4 py-2 text-right font-mono font-semibold" :class="d.gain_loss >= 0 ? 'text-green-700' : 'text-red-600'">
                {{ fmt(d.gain_loss) }}
              </td>
              <td class="px-4 py-2"><span class="erp-badge" :class="d.status === 'posted' ? 'erp-badge-green' : 'erp-badge-yellow'">{{ d.status === 'posted' ? 'Ghi sổ' : 'Nháp' }}</span></td>
            </tr>
            <tr v-if="disposals.length === 0">
              <td colspan="5" class="px-4 py-8 text-center text-slate-400">Chưa có thanh lý.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal: Đưa vào sử dụng -->
    <Modal :show="showPlaceInService" @close="showPlaceInService = false" title="Đưa vào sử dụng">
      <form @submit.prevent="doPlaceInService" class="space-y-4">
        <div>
          <label class="erp-label">Ngày đưa vào sử dụng <span class="text-red-500">*</span></label>
          <input v-model="placeInServiceForm.date" type="date" class="erp-input w-full" required />
        </div>
        <div>
          <label class="erp-label">Bộ phận sử dụng</label>
          <input v-model="placeInServiceForm.department" class="erp-input w-full" />
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="showPlaceInService = false" class="erp-btn-secondary">Hủy</button>
          <button type="submit" class="erp-btn-primary">Xác nhận</button>
        </div>
      </form>
    </Modal>

    <!-- Modal: Tạm dừng khấu hao -->
    <Modal :show="showSuspend" @close="showSuspend = false" title="Tạm dừng khấu hao">
      <form @submit.prevent="doSuspend" class="space-y-4">
        <div>
          <label class="erp-label">Ngày tạm dừng <span class="text-red-500">*</span></label>
          <input v-model="suspendForm.date" type="date" class="erp-input w-full" required />
        </div>
        <div>
          <label class="erp-label">Lý do <span class="text-red-500">*</span></label>
          <textarea v-model="suspendForm.reason" class="erp-input w-full" rows="2" required />
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="showSuspend = false" class="erp-btn-secondary">Hủy</button>
          <button type="submit" class="erp-btn-primary">Xác nhận tạm dừng</button>
        </div>
      </form>
    </Modal>

    <!-- Modal: Điều chuyển -->
    <Modal :show="showTransfer" @close="showTransfer = false" title="Điều chuyển bộ phận">
      <form @submit.prevent="doTransfer" class="space-y-4">
        <div>
          <label class="erp-label">Bộ phận đến <span class="text-red-500">*</span></label>
          <input v-model="transferForm.to_department" class="erp-input w-full" required />
        </div>
        <div>
          <label class="erp-label">Ngày hiệu lực <span class="text-red-500">*</span></label>
          <input v-model="transferForm.effective_date" type="date" class="erp-input w-full" required />
        </div>
        <div>
          <label class="erp-label">TK chi phí KH mới (nếu thay đổi)</label>
          <input v-model="transferForm.to_expense_account_code" class="erp-input w-full font-mono" placeholder="6422" />
        </div>
        <div>
          <label class="erp-label">Ghi chú</label>
          <textarea v-model="transferForm.notes" class="erp-input w-full" rows="2" />
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="showTransfer = false" class="erp-btn-secondary">Hủy</button>
          <button type="submit" class="erp-btn-primary">Ghi nhận</button>
        </div>
      </form>
    </Modal>

    <!-- Modal: Confirm reverse depreciation -->
    <Modal :show="!!reverseTarget" @close="reverseTarget = null" title="Hủy khấu hao">
      <p class="text-slate-700">Hủy khấu hao kỳ <strong>{{ reverseTarget?.period }}</strong> — {{ fmt(reverseTarget?.amount) }}?</p>
      <div class="flex justify-end gap-2 mt-4">
        <button @click="reverseTarget = null" class="erp-btn-secondary">Không</button>
        <button @click="doReverse" class="erp-btn-danger">Hủy khấu hao</button>
      </div>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import Modal from '@/Components/Shared/Modal.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  asset: Object,
  schedule: Array,
  movements: Array,
  repairs: Array,
  disposals: Array,
  depreciations: Object,
});

const { can } = usePermission();
const { formatVnd } = useCurrency();
const fmt = (v) => formatVnd(v);

const activeTab = ref('info');
const tabs = [
  { id: 'info',       label: 'Thông tin chung' },
  { id: 'accounting', label: 'Kế toán' },
  { id: 'schedule',   label: 'Lịch khấu hao' },
  { id: 'journals',   label: 'Bút toán KH' },
  { id: 'movements',  label: 'Điều chuyển' },
  { id: 'repairs',    label: 'Sửa chữa' },
  { id: 'disposals',  label: 'Thanh lý' },
];

// Modals
const showPlaceInService = ref(false);
const showSuspend = ref(false);
const showTransfer = ref(false);
const reverseTarget = ref(null);

const placeInServiceForm = ref({ date: '', department: '' });
const suspendForm = ref({ date: '', reason: '' });
const transferForm = ref({ to_department: '', effective_date: '', to_expense_account_code: '', notes: '' });

function doPlaceInService() {
  router.post(route('accounting.fixed-assets.place-in-service', props.asset.id), {
    placed_in_service_date: placeInServiceForm.value.date,
    department: placeInServiceForm.value.department,
  }, { onSuccess: () => { showPlaceInService.value = false; } });
}

function doSuspend() {
  router.post(route('accounting.fixed-assets.suspend', props.asset.id), {
    suspend_date: suspendForm.value.date,
    reason: suspendForm.value.reason,
  }, { onSuccess: () => { showSuspend.value = false; } });
}

function doResume() {
  router.post(route('accounting.fixed-assets.resume', props.asset.id), {
    resume_date: new Date().toISOString().slice(0, 10),
  });
}

function doTransfer() {
  router.post(route('accounting.fixed-assets.transfer', props.asset.id), transferForm.value, {
    onSuccess: () => { showTransfer.value = false; },
  });
}

function confirmReverse(dep) { reverseTarget.value = dep; }
function doReverse() {
  router.post(route('accounting.fixed-assets.depreciation.reverse', reverseTarget.value.id), {}, {
    onSuccess: () => { reverseTarget.value = null; },
  });
}

// Labels
const assetTypeLabel = computed(() => ({ tangible: 'TSCĐ hữu hình', intangible: 'TSCĐ vô hình', finance_lease: 'TSCĐ thuê tài chính' })[props.asset.asset_type] || props.asset.asset_type);
const sourceTypeLabel = computed(() => ({ purchased: 'Mua ngoài', self_built: 'Tự xây dựng', contributed: 'Nhận góp vốn', transferred: 'Điều chuyển', imported: 'Nhập khẩu', other: 'Khác' })[props.asset.source_type] || '—');

function badgeClass(color) {
  return { green: 'erp-badge-green', yellow: 'erp-badge-yellow', orange: 'erp-badge-orange', red: 'erp-badge-red', gray: 'erp-badge-gray' }[color] || 'erp-badge-gray';
}

function movementLabel(type) {
  return { placed_in_service: 'Đưa vào sử dụng', department_transfer: 'Điều chuyển', account_change: 'Đổi TK', suspended: 'Tạm dừng', resumed: 'Tiếp tục', revaluation: 'Đánh giá lại', other: 'Khác' }[type] || type;
}

function repairTypeLabel(type) {
  return { regular: 'Sửa chữa thường', major_repair: 'Sửa chữa lớn', upgrade: 'Nâng cấp' }[type] || type;
}

function treatmentLabel(t) {
  return { expense_now: 'Ghi chi phí', prepaid_allocation: 'Phân bổ 242', increase_original_cost: 'Tăng nguyên giá' }[t] || t;
}

function disposalTypeLabel(type) {
  return { liquidation: 'Thanh lý', sale: 'Nhượng bán', damage: 'Mất mát/Hư hỏng', other: 'Khác' }[type] || type;
}
</script>
