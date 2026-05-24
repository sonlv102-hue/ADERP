<template>
  <AppLayout>
    <div class="max-w-3xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('purchasing.purchase-contracts.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ contract.code }}</h1>
          <StatusBadge :color="contract.status_color">{{ contract.status_label }}</StatusBadge>
        </div>
        <div class="flex items-center gap-2">
          <Link v-if="contract.status === 'draft'"
            :href="route('purchasing.purchase-contracts.edit', contract.id)"
            class="px-4 py-2 border border-gray-300 text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-50">
            Sửa
          </Link>
          <button v-if="contract.status === 'draft'" @click="action('activate')"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Kích hoạt
          </button>
          <button v-if="contract.status === 'active'" @click="action('complete')"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Hoàn thành
          </button>
          <button v-if="['draft','active'].includes(contract.status)" @click="action('terminate')"
            class="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50">
            Chấm dứt
          </button>
          <button v-if="contract.status === 'draft'" @click="deleteContract"
            class="px-4 py-2 border border-red-200 text-red-500 rounded-lg text-sm font-medium hover:bg-red-50">
            Xóa
          </button>
        </div>
      </div>

      <!-- Thông tin -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Thông tin hợp đồng</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-4 gap-x-8 text-sm">
          <div>
            <span class="text-gray-500">Nhà cung cấp</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ contract.supplier.name }}</p>
          </div>
          <div>
            <span class="text-gray-500">Đơn mua liên kết</span>
            <p class="mt-0.5">
              <Link v-if="contract.order" :href="route('purchasing.purchase-orders.show', contract.order.id)"
                class="font-mono text-primary-600 hover:underline font-medium">{{ contract.order.code }}</Link>
              <span v-else class="text-gray-400">—</span>
            </p>
          </div>
          <div>
            <span class="text-gray-500">Giá trị hợp đồng</span>
            <p class="font-bold text-primary-700 mt-0.5">{{ formatVnd(contract.value) }}</p>
          </div>
          <div>
            <span class="text-gray-500">Ngày bắt đầu</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ contract.start_date ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Ngày kết thúc</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ contract.end_date ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Người tạo</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ contract.creator }} — {{ contract.created_at }}</p>
          </div>
          <div v-if="contract.notes" class="sm:col-span-2 lg:col-span-3">
            <span class="text-gray-500">Ghi chú</span>
            <p class="font-medium text-gray-900 mt-0.5 whitespace-pre-line">{{ contract.notes }}</p>
          </div>
        </div>
      </div>

      <!-- Lịch thanh toán -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <div>
            <h2 class="text-base font-semibold text-gray-800">Lịch thanh toán</h2>
            <p class="text-xs text-gray-500 mt-0.5">
              Tổng đã lập: <strong>{{ contract.total_percent }}%</strong>
              &nbsp;·&nbsp; Đã thanh toán: <strong class="text-green-700">{{ contract.paid_percent }}%</strong>
              ({{ formatVnd(contract.paid_amount) }})
              &nbsp;·&nbsp; Còn lại:
              <strong :class="contract.remaining_amount > 0 ? 'text-red-600' : 'text-green-700'">
                {{ formatVnd(contract.remaining_amount) }}
              </strong>
            </p>
          </div>
        </div>

        <!-- Progress bar -->
        <div class="px-6 pt-3 pb-1" v-if="contract.schedules.length">
          <div class="w-full bg-gray-100 rounded-full h-2.5 overflow-hidden">
            <div class="h-2.5 rounded-full bg-green-500 transition-all"
              :style="{ width: contract.paid_percent + '%' }"></div>
          </div>
          <p class="text-xs text-gray-400 mt-1 text-right">{{ contract.paid_percent }}% / 100%</p>
        </div>

        <!-- Bảng đợt thanh toán -->
        <table v-if="contract.schedules.length" class="w-full text-sm">
          <thead class="bg-gray-50 border-y border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đợt</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">%</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Số tiền</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Hạn TT</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Ngày TT</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template v-for="s in contract.schedules" :key="s.id">
              <!-- View row -->
              <tr v-if="editingId !== s.id" class="hover:bg-gray-50">
                <td class="px-5 py-3 font-medium text-gray-900">{{ s.name }}</td>
                <td class="px-4 py-3 text-right text-gray-700">{{ s.percentage }}%</td>
                <td class="px-4 py-3 text-right font-semibold text-gray-900">{{ formatVnd(s.amount) }}</td>
                <td class="px-4 py-3 text-gray-600">
                  <span :class="s.status === 'overdue' ? 'text-red-600 font-medium' : ''">
                    {{ s.due_date_label ?? '—' }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <StatusBadge :color="s.status_color">{{ s.status_label }}</StatusBadge>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500">
                  <template v-if="s.paid_date">
                    <p>{{ s.paid_date }}</p>
                    <p class="text-gray-400">
                      {{ s.payment_method === 'bank_transfer' ? 'CK' : s.payment_method === 'cash' ? 'TM' : 'Khác' }}
                      <span v-if="s.payment_reference"> · {{ s.payment_reference }}</span>
                    </p>
                  </template>
                  <span v-else>—</span>
                </td>
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2 justify-end">
                    <template v-if="s.status !== 'paid'">
                      <button @click="openMarkPaid(s)"
                        class="text-xs px-2.5 py-1 bg-green-600 hover:bg-green-700 text-white rounded-md font-medium">
                        Đã TT
                      </button>
                      <button @click="startEdit(s)"
                        class="text-xs px-2.5 py-1 border border-gray-300 text-gray-600 rounded-md hover:bg-gray-50">
                        Sửa
                      </button>
                      <button @click="deleteSchedule(s.id)"
                        class="text-xs px-2.5 py-1 border border-red-200 text-red-500 rounded-md hover:bg-red-50">
                        Xóa
                      </button>
                    </template>
                    <button v-else @click="markPending(s.id)"
                      class="text-xs px-2.5 py-1 border border-gray-300 text-gray-500 rounded-md hover:bg-gray-50">
                      Hoàn tác
                    </button>
                  </div>
                </td>
              </tr>

              <!-- Inline edit row -->
              <tr v-else class="bg-blue-50">
                <td class="px-5 py-2">
                  <input v-model="editForm.name" class="w-full px-2 py-1 border border-gray-300 rounded text-sm" />
                </td>
                <td class="px-4 py-2">
                  <input v-model="editForm.percentage" type="number" min="0.01" max="100" step="0.01"
                    class="w-20 px-2 py-1 border border-gray-300 rounded text-sm text-right" />
                </td>
                <td class="px-4 py-2 text-right text-xs text-gray-500">
                  {{ formatVnd(contract.value * editForm.percentage / 100) }}
                </td>
                <td class="px-4 py-2">
                  <input v-model="editForm.due_date" type="date"
                    class="w-36 px-2 py-1 border border-gray-300 rounded text-sm" />
                </td>
                <td colspan="2"></td>
                <td class="px-4 py-2">
                  <div class="flex gap-2 justify-end">
                    <button @click="saveEdit(s.id)"
                      class="text-xs px-2.5 py-1 bg-primary-600 hover:bg-primary-700 text-white rounded-md">Lưu</button>
                    <button @click="editingId = null"
                      class="text-xs px-2.5 py-1 border border-gray-300 text-gray-600 rounded-md">Hủy</button>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td class="px-5 py-2 text-sm font-semibold text-gray-700">Tổng</td>
              <td class="px-4 py-2 text-right font-semibold"
                :class="contract.total_percent > 100 ? 'text-red-600' : contract.total_percent === 100 ? 'text-green-700' : 'text-gray-700'">
                {{ contract.total_percent }}%
              </td>
              <td class="px-4 py-2 text-right font-semibold text-gray-900">{{ formatVnd(contract.value) }}</td>
              <td colspan="4"></td>
            </tr>
          </tfoot>
        </table>

        <p v-else class="px-6 py-8 text-sm text-center text-gray-400">Chưa có đợt thanh toán nào.</p>

        <!-- Form thêm đợt -->
        <div v-if="contract.total_percent < 100" class="px-6 py-4 border-t border-gray-200 bg-gray-50">
          <p class="text-xs font-semibold text-gray-600 mb-3">Thêm đợt thanh toán</p>
          <div class="flex flex-wrap gap-3 items-end">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Tên đợt <span class="text-red-500">*</span></label>
              <input v-model="addForm.name" type="text" placeholder="VD: Đặt cọc 30%"
                class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm w-48"
                :class="{ 'border-red-500': addErrors.name }" />
              <p v-if="addErrors.name" class="text-xs text-red-500 mt-0.5">{{ addErrors.name }}</p>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">% giá trị HĐ <span class="text-red-500">*</span></label>
              <div class="flex items-center gap-1">
                <input v-model="addForm.percentage" type="number" min="0.01" max="100" step="0.01"
                  class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm w-24 text-right"
                  :class="{ 'border-red-500': addErrors.percentage }" />
                <span class="text-sm text-gray-500">%</span>
              </div>
              <p class="text-xs text-gray-400 mt-0.5">≈ {{ formatVnd(contract.value * (addForm.percentage || 0) / 100) }}</p>
              <p v-if="addErrors.percentage" class="text-xs text-red-500">{{ addErrors.percentage }}</p>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Hạn thanh toán</label>
              <input v-model="addForm.due_date" type="date"
                class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm w-40" />
            </div>
            <button @click="addSchedule"
              class="px-4 py-1.5 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-lg font-medium">
              Thêm đợt
            </button>
          </div>
        </div>
        <div v-else class="px-6 py-3 border-t border-gray-200 bg-green-50">
          <p class="text-xs text-green-700 font-medium">✓ Lịch thanh toán đã đủ 100% giá trị hợp đồng.</p>
        </div>
      </div>

      <!-- Mark paid modal -->
      <Teleport to="body">
        <div v-if="markPaidModal.show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-1">Xác nhận đã thanh toán</h3>
            <p class="text-sm text-gray-600 mb-4">Đợt: <strong>{{ markPaidModal.name }}</strong></p>
            <div class="space-y-3 mb-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Ngày thanh toán <span class="text-red-500">*</span></label>
                <input v-model="markPaidModal.paid_date" type="date"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Hình thức thanh toán <span class="text-red-500">*</span></label>
                <select v-model="markPaidModal.payment_method"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white">
                  <option value="bank_transfer">Chuyển khoản ngân hàng</option>
                  <option value="cash">Tiền mặt</option>
                  <option value="other">Khác</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mã GD / Số chứng từ</label>
                <input v-model="markPaidModal.payment_reference" type="text" placeholder="VD: FT26052400123"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
              </div>
            </div>
            <div class="flex justify-end gap-2">
              <button @click="markPaidModal.show = false"
                class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</button>
              <button @click="submitMarkPaid"
                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium">Xác nhận</button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Tài liệu đính kèm -->
      <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-sm font-semibold text-gray-700 mb-3">Tài liệu đính kèm (file hợp đồng)</p>
        <div v-if="contract.file_name" class="flex items-center gap-3 px-3 py-2 bg-gray-50 rounded-lg">
          <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
          </svg>
          <span class="text-sm text-gray-800 flex-1 truncate">{{ contract.file_name }}</span>
          <a :href="contract.file_url" target="_blank" download
            class="text-primary-600 hover:text-primary-800 text-xs font-medium whitespace-nowrap">Tải xuống</a>
          <button @click="deleteFile"
            class="text-red-500 hover:text-red-700 text-xs font-medium whitespace-nowrap">Xóa</button>
        </div>
        <div v-else class="space-y-2">
          <label class="block cursor-pointer">
            <input type="file" class="hidden" ref="fileInput" @change="onFileSelected">
            <div class="px-3 py-2 text-sm text-gray-500 bg-gray-50 border border-dashed border-gray-300 rounded-lg hover:bg-gray-100 text-center">
              {{ selectedFile ? selectedFile.name : 'Nhấn để chọn file hợp đồng (PDF, Word, ảnh...)' }}
            </div>
          </label>
          <div v-if="selectedFile" class="flex justify-end">
            <button @click="uploadFile" :disabled="uploading"
              class="px-4 py-1.5 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm rounded-lg">
              {{ uploading ? 'Đang tải...' : 'Đính kèm' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ contract: Object });
const { formatVnd } = useCurrency();

// --- Contract actions ---
const action = (act) => {
  router.post(route(`purchasing.purchase-contracts.${act}`, props.contract.id));
};
const deleteContract = () => {
  if (confirm('Xóa hợp đồng này?')) {
    router.delete(route('purchasing.purchase-contracts.destroy', props.contract.id));
  }
};

// --- Add schedule form ---
const addForm = reactive({ name: '', percentage: '', due_date: '' });
const addErrors = reactive({ name: '', percentage: '' });

const addSchedule = () => {
  addErrors.name = addForm.name ? '' : 'Bắt buộc';
  addErrors.percentage = addForm.percentage ? '' : 'Bắt buộc';
  if (addErrors.name || addErrors.percentage) return;

  router.post(
    route('purchasing.purchase-contracts.schedules.store', props.contract.id),
    { name: addForm.name, percentage: addForm.percentage, due_date: addForm.due_date || null },
    {
      preserveScroll: true,
      onSuccess: () => { addForm.name = ''; addForm.percentage = ''; addForm.due_date = ''; },
      onError: (errors) => {
        addErrors.name = errors.name ?? '';
        addErrors.percentage = errors.percentage ?? '';
      },
    }
  );
};

// --- Inline edit ---
const editingId = ref(null);
const editForm = reactive({ name: '', percentage: '', due_date: '' });

const startEdit = (s) => {
  editingId.value = s.id;
  editForm.name = s.name;
  editForm.percentage = s.percentage;
  editForm.due_date = s.due_date ?? '';
};

const saveEdit = (id) => {
  router.put(
    route('purchasing.purchase-contracts.schedules.update', [props.contract.id, id]),
    { name: editForm.name, percentage: editForm.percentage, due_date: editForm.due_date || null },
    { preserveScroll: true, onSuccess: () => { editingId.value = null; } }
  );
};

const deleteSchedule = (id) => {
  if (confirm('Xóa đợt thanh toán này?')) {
    router.delete(
      route('purchasing.purchase-contracts.schedules.destroy', [props.contract.id, id]),
      { preserveScroll: true }
    );
  }
};

// --- Mark paid modal ---
const markPaidModal = reactive({
  show: false, id: null, name: '',
  paid_date: new Date().toISOString().slice(0, 10),
  payment_method: 'bank_transfer',
  payment_reference: '',
});

const openMarkPaid = (s) => {
  markPaidModal.show = true;
  markPaidModal.id = s.id;
  markPaidModal.name = s.name;
  markPaidModal.paid_date = new Date().toISOString().slice(0, 10);
  markPaidModal.payment_method = 'bank_transfer';
  markPaidModal.payment_reference = '';
};

const submitMarkPaid = () => {
  if (!markPaidModal.paid_date || !markPaidModal.payment_method) return;
  router.post(
    route('purchasing.purchase-contracts.schedules.mark-paid', [props.contract.id, markPaidModal.id]),
    {
      paid_date:          markPaidModal.paid_date,
      payment_method:     markPaidModal.payment_method,
      payment_reference:  markPaidModal.payment_reference || null,
    },
    { preserveScroll: true, onSuccess: () => { markPaidModal.show = false; } }
  );
};

const markPending = (id) => {
  if (!confirm('Hoàn tác xác nhận thanh toán đợt này?')) return;
  router.post(
    route('purchasing.purchase-contracts.schedules.mark-pending', [props.contract.id, id]),
    {},
    { preserveScroll: true }
  );
};

// --- File attachment ---
const fileInput = ref(null);
const selectedFile = ref(null);
const uploading = ref(false);

const onFileSelected = (e) => { selectedFile.value = e.target.files[0] ?? null; };

const uploadFile = () => {
  if (!selectedFile.value) return;
  const formData = new FormData();
  formData.append('file', selectedFile.value);
  uploading.value = true;
  router.post(route('purchasing.purchase-contracts.attachment.upload', props.contract.id), formData, {
    preserveScroll: true,
    onSuccess: () => { selectedFile.value = null; if (fileInput.value) fileInput.value.value = ''; },
    onFinish: () => { uploading.value = false; },
  });
};

const deleteFile = () => {
  if (confirm('Xóa file đính kèm?')) {
    router.delete(route('purchasing.purchase-contracts.attachment.delete', props.contract.id), { preserveScroll: true });
  }
};
</script>
