<template>
  <Modal :show="true" max-width="2xl" @close="$emit('close')">
    <template #title>Import báo giá NCC</template>

    <div v-if="phase === 'form'" class="space-y-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <FormField label="Nhà cung cấp" required :error="err.supplier_id">
          <RemoteSearchSelect
            v-model="form.supplier_id"
            :search-url="route('search.suppliers')"
            placeholder="Tìm NCC..."
            :has-error="!!err.supplier_id"
          />
        </FormField>
        <FormField label="Số báo giá">
          <input v-model="form.quote_no" class="erp-input" />
        </FormField>
        <FormField label="Ngày báo giá">
          <input v-model="form.quote_date" type="date" class="erp-input" />
        </FormField>
        <FormField label="Hiệu lực đến">
          <input v-model="form.valid_until" type="date" class="erp-input" />
        </FormField>
        <FormField label="Tiền tệ">
          <input v-model="form.currency" class="erp-input" />
        </FormField>
        <FormField label="Phí vận chuyển">
          <input v-model="form.shipping_fee" type="number" min="0" step="any" class="erp-input" />
        </FormField>
        <FormField label="Điều khoản thanh toán" wrap-class="sm:col-span-2">
          <input v-model="form.payment_terms" class="erp-input" />
        </FormField>
        <FormField label="Ghi chú" wrap-class="sm:col-span-2">
          <input v-model="form.note" class="erp-input" />
        </FormField>
      </div>

      <FormField label="File báo giá (.xlsx)" required :error="err.file">
        <input type="file" accept=".xlsx" class="erp-input" @change="e => form.file = e.target.files[0]" />
      </FormField>
      <p class="text-xs text-gray-400 -mt-2">
        Chỉ nhận đúng file tải từ nút <span class="font-medium">"Xuất mẫu báo giá"</span> (Tab 1). Không đổi thứ tự cột, không xóa 2 dòng đầu.
      </p>

      <p v-if="topError" class="text-sm text-red-600">{{ topError }}</p>
    </div>

    <div v-else class="space-y-4">
      <div class="flex flex-wrap gap-2 text-sm">
        <span class="px-3 py-1 rounded-lg bg-green-50 text-green-700 font-medium">{{ preview.valid_rows }} hợp lệ</span>
        <span class="px-3 py-1 rounded-lg bg-yellow-50 text-yellow-700 font-medium">{{ preview.warning_rows }} cảnh báo</span>
        <span class="px-3 py-1 rounded-lg bg-red-50 text-red-700 font-medium">{{ preview.error_rows }} lỗi</span>
        <span class="px-3 py-1 rounded-lg bg-gray-50 text-gray-600">Tổng {{ preview.total_rows }} dòng</span>
      </div>

      <div v-if="preview.errors.length" class="rounded-lg border border-red-200 bg-red-50 p-3 max-h-40 overflow-y-auto">
        <p class="text-xs font-semibold text-red-700 mb-1">Lỗi — không thể import:</p>
        <ul class="text-xs text-red-700 space-y-0.5 list-disc pl-4">
          <li v-for="(e, i) in preview.errors" :key="i">Dòng {{ e.row }}: {{ e.message }}</li>
        </ul>
      </div>

      <div v-if="preview.warnings.length" class="rounded-lg border border-yellow-200 bg-yellow-50 p-3 max-h-32 overflow-y-auto">
        <ul class="text-xs text-yellow-800 space-y-0.5 list-disc pl-4">
          <li v-for="(w, i) in preview.warnings" :key="i">Dòng {{ w.row }}: {{ w.message }}</li>
        </ul>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto max-h-72 overflow-y-auto">
        <table class="min-w-full text-xs">
          <thead class="bg-gray-50 border-b border-gray-200 sticky top-0">
            <tr>
              <th class="text-left px-3 py-2 font-semibold text-gray-600">Dòng</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600">Mã hàng</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600">Tên hàng</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600">ĐVT</th>
              <th class="text-right px-3 py-2 font-semibold text-gray-600">SL</th>
              <th class="text-right px-3 py-2 font-semibold text-gray-600">Đơn giá</th>
              <th class="text-right px-3 py-2 font-semibold text-gray-600">CK%</th>
              <th class="text-right px-3 py-2 font-semibold text-gray-600">VAT%</th>
              <th class="text-right px-3 py-2 font-semibold text-gray-600">Giá sau CK</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600">TT</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="r in preview.rows" :key="r.row">
              <td class="px-3 py-1.5 text-gray-500">{{ r.row }}</td>
              <td class="px-3 py-1.5 font-mono text-primary-700">{{ r.product_code }}</td>
              <td class="px-3 py-1.5 text-gray-800">{{ r.product_name }}</td>
              <td class="px-3 py-1.5 text-gray-600">{{ r.unit }}</td>
              <td class="px-3 py-1.5 text-right">{{ fmt(r.qty) }}</td>
              <td class="px-3 py-1.5 text-right">{{ fmt(r.unit_price) }}</td>
              <td class="px-3 py-1.5 text-right">{{ r.discount }}</td>
              <td class="px-3 py-1.5 text-right">{{ r.vat }}</td>
              <td class="px-3 py-1.5 text-right font-medium">{{ fmt(r.net) }}</td>
              <td class="px-3 py-1.5">
                <span :class="r.status === 'warning' ? 'text-yellow-600' : 'text-green-600'">
                  {{ r.status === 'warning' ? 'Cảnh báo' : 'Hợp lệ' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="topError" class="text-sm text-red-600">{{ topError }}</p>
    </div>

    <template #footer>
      <button class="erp-btn-secondary" @click="$emit('close')">Hủy</button>
      <button v-if="phase === 'form'" class="erp-btn-primary" :disabled="busy" @click="doPreview">Tải & xem trước</button>
      <template v-else>
        <button class="erp-btn-secondary" @click="phase = 'form'">Quay lại</button>
        <button class="erp-btn-primary" :disabled="!preview.can_confirm || busy" @click="doConfirm">Xác nhận import</button>
      </template>
    </template>
  </Modal>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Shared/Modal.vue';
import FormField from '@/Components/Shared/FormField.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';

const props = defineProps({ comparisonId: Number });
const emit = defineEmits(['close', 'imported']);

const phase = ref('form');
const busy = ref(false);
const topError = ref('');
const err = ref({});
const preview = ref(null);

const form = ref({
  supplier_id: null, quote_no: '', quote_date: '', valid_until: '',
  currency: 'VND', payment_terms: '', shipping_fee: 0, note: '', file: null,
});

function fmt(v) {
  return new Intl.NumberFormat('vi-VN').format(v || 0);
}

async function doPreview() {
  err.value = {};
  topError.value = '';
  if (!form.value.supplier_id) { err.value.supplier_id = 'Chọn nhà cung cấp.'; return; }
  if (!form.value.file) { err.value.file = 'Chọn file .xlsx.'; return; }

  const fd = new FormData();
  Object.entries(form.value).forEach(([k, v]) => { if (v !== null && v !== '') fd.append(k, v); });

  busy.value = true;
  try {
    const { data } = await axios.post(
      route('purchasing.quote-comparisons.quotes.preview', props.comparisonId), fd,
    );
    preview.value = data;
    phase.value = 'preview';
  } catch (e) {
    if (e.response?.status === 422 && e.response.data?.errors) {
      err.value = Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]));
    }
    topError.value = e.response?.data?.message ?? 'Không đọc được file.';
  } finally {
    busy.value = false;
  }
}

async function doConfirm() {
  busy.value = true;
  topError.value = '';
  try {
    await axios.post(route('purchasing.quote-comparisons.quotes.confirm', props.comparisonId), {
      preview_id: preview.value.preview_id,
    });
    emit('imported');
  } catch (e) {
    topError.value = e.response?.data?.message ?? 'Import thất bại.';
  } finally {
    busy.value = false;
  }
}
</script>
