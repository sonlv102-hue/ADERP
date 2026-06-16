<template>
  <AppLayout :title="isEdit ? 'Sửa ứng trước' : 'Thêm ứng trước đầu kỳ'">
    <div class="max-w-2xl mx-auto px-4 py-6">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('purchasing.supplier-advances.index')" class="text-gray-500 hover:text-gray-700">
          ← Danh sách ứng trước
        </Link>
        <span class="text-gray-400">/</span>
        <h1 class="text-xl font-bold text-gray-900">
          {{ isEdit ? 'Sửa khoản ứng trước' : 'Thêm ứng trước đầu kỳ' }}
        </h1>
      </div>

      <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 text-sm text-blue-800">
        <strong>Lưu ý:</strong> Khoản ứng trước đầu kỳ là số tiền đã chuyển trước cho NCC từ năm trước nhưng chưa nhận hàng/hóa đơn.
        Sang năm 2026, số dư này là <strong>Nợ TK 3311</strong> theo từng nhà cung cấp.
        Không ghi lại bút toán Có 1111/1121 khi đối trừ.
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-lg shadow p-6 space-y-5">
        <div>
          <label class="label">Nhà cung cấp <span class="text-red-500">*</span></label>
          <select v-model="form.supplier_id" class="input w-full" :disabled="isEdit && hasAllocations">
            <option value="">-- Chọn nhà cung cấp --</option>
            <option v-for="s in suppliers" :key="s.id" :value="s.id">
              [{{ s.code }}] {{ s.name }}
            </option>
          </select>
          <p v-if="errors.supplier_id" class="error">{{ errors.supplier_id }}</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">Năm tài chính <span class="text-red-500">*</span></label>
            <input v-model.number="form.fiscal_year" type="number" min="2020" max="2099" class="input w-full" />
            <p v-if="errors.fiscal_year" class="error">{{ errors.fiscal_year }}</p>
          </div>
          <div>
            <label class="label">Ngày đầu kỳ <span class="text-red-500">*</span></label>
            <input v-model="form.opening_date" type="date" class="input w-full" />
            <p v-if="errors.opening_date" class="error">{{ errors.opening_date }}</p>
          </div>
        </div>

        <div>
          <label class="label">Số tiền ứng trước (VND) <span class="text-red-500">*</span></label>
          <input
            v-model.number="form.amount"
            type="number"
            min="1"
            step="1"
            class="input w-full font-mono"
            :disabled="isEdit && hasAllocations"
          />
          <p v-if="errors.amount" class="error">{{ errors.amount }}</p>
          <p v-if="isEdit && hasAllocations" class="text-sm text-amber-600 mt-1">
            Không thể sửa số tiền khi đã có đối trừ.
          </p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">Mã tham chiếu</label>
            <input v-model="form.reference_no" type="text" class="input w-full" placeholder="Số phiếu chuyển khoản..." />
          </div>
          <div>
            <label class="label">Mã giao dịch ngân hàng</label>
            <input v-model="form.bank_transaction_ref" type="text" class="input w-full" />
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="label">Ngày chuyển khoản gốc</label>
            <input v-model="form.original_payment_date" type="date" class="input w-full" />
          </div>
          <div>
            <label class="label">Diễn giải chuyển khoản gốc</label>
            <input v-model="form.original_payment_note" type="text" class="input w-full" />
          </div>
        </div>

        <div>
          <label class="label">Ghi chú</label>
          <textarea v-model="form.notes" rows="2" class="input w-full"></textarea>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" class="btn-primary" :disabled="processing">
            {{ processing ? 'Đang lưu...' : (isEdit ? 'Cập nhật' : 'Tạo khoản ứng trước') }}
          </button>
          <Link :href="route('purchasing.supplier-advances.index')" class="btn-secondary">
            Hủy
          </Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  advance:   Object,
  suppliers: Array,
})

const isEdit = computed(() => !!props.advance?.id)
const hasAllocations = computed(() => props.advance?.active_allocations_count > 0)

const form = useForm({
  supplier_id:           props.advance?.supplier_id ?? '',
  fiscal_year:           props.advance?.fiscal_year ?? new Date().getFullYear(),
  opening_date:          props.advance?.opening_date ?? '',
  amount:                props.advance?.amount ?? '',
  reference_no:          props.advance?.reference_no ?? '',
  bank_transaction_ref:  props.advance?.bank_transaction_ref ?? '',
  original_payment_date: props.advance?.original_payment_date ?? '',
  original_payment_note: props.advance?.original_payment_note ?? '',
  notes:                 props.advance?.notes ?? '',
})

const errors = computed(() => form.errors)
const processing = computed(() => form.processing)

function submit() {
  if (isEdit.value) {
    form.put(route('purchasing.supplier-advances.update', props.advance.id))
  } else {
    form.post(route('purchasing.supplier-advances.store'))
  }
}
</script>
