<template>
  <AppLayout :title="pageTitle">
    <div class="max-w-2xl mx-auto space-y-5">

      <!-- Header -->
      <div>
        <nav class="flex items-center gap-2 text-sm text-gray-500 mb-1">
          <Link :href="route('purchasing.supplier-advances.index')" class="hover:text-primary-600">
            Tiền trả trước NCC
          </Link>
          <span>/</span>
          <span class="text-gray-700">{{ isEdit ? 'Sửa' : 'Thêm mới' }}</span>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900">{{ pageTitle }}</h1>
      </div>

      <!-- Info note -->
      <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex gap-3">
        <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-sm text-blue-800" v-if="form.advance_type === 'prepayment'">
          <strong>Trả trước trong kỳ:</strong> Hệ thống tạo Phiếu Chi (Dr 331UT / Cr Quỹ).
          Khi đối trừ vào hóa đơn sau, bút toán Dr 3311 / Cr 331UT sẽ được tạo tự động.
        </p>
        <p class="text-sm text-blue-800" v-else>
          <strong>Số dư đầu kỳ:</strong> Số tiền đã chuyển trước cho NCC trước kỳ này, chưa có hóa đơn.
          Khi đối trừ vào hóa đơn, hệ thống ghi nhận allocation mà không tạo dòng tiền mới.
        </p>
      </div>

      <!-- Form -->
      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">

        <!-- Loại ứng trước (chỉ hiện khi tạo mới) -->
        <div v-if="!isEdit" class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-2">Loại khoản trả trước</label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" v-model="form.advance_type" value="opening_balance" class="text-primary-600" />
              <span class="text-sm">Số dư đầu kỳ (không tạo phiếu chi)</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" v-model="form.advance_type" value="prepayment" class="text-primary-600" />
              <span class="text-sm">Trả trước trong kỳ (tạo phiếu chi Dr 331UT)</span>
            </label>
          </div>
        </div>

        <!-- Nhà cung cấp -->
        <div class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">
            Nhà cung cấp <span class="text-red-500">*</span>
          </label>
          <select v-model="form.supplier_id"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
            :disabled="isEdit && hasAllocations">
            <option value="">-- Chọn nhà cung cấp --</option>
            <option v-for="s in suppliers" :key="s.id" :value="s.id">
              [{{ s.code }}] {{ s.name }}
            </option>
          </select>
          <p v-if="errors.supplier_id" class="mt-1 text-xs text-red-600">{{ errors.supplier_id }}</p>
        </div>

        <!-- Quỹ/Ngân hàng (chỉ cho prepayment) -->
        <div v-if="form.advance_type === 'prepayment'" class="px-6 py-5 grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
              Quỹ / Tài khoản ngân hàng <span class="text-red-500">*</span>
            </label>
            <select v-model="form.fund_id"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="">-- Chọn quỹ --</option>
              <option v-for="f in funds" :key="f.id" :value="f.id">
                {{ f.name }} ({{ f.type === 'cash' ? 'Tiền mặt' : 'Ngân hàng' }})
              </option>
            </select>
            <p v-if="errors.fund_id" class="mt-1 text-xs text-red-600">{{ errors.fund_id }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
              Hình thức <span class="text-red-500">*</span>
            </label>
            <select v-model="form.payment_method"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="cash">Tiền mặt</option>
              <option value="bank_transfer">Chuyển khoản</option>
            </select>
            <p v-if="errors.payment_method" class="mt-1 text-xs text-red-600">{{ errors.payment_method }}</p>
          </div>
        </div>

        <!-- Năm + Ngày -->
        <div class="px-6 py-5 grid grid-cols-2 gap-4">
          <div v-if="form.advance_type === 'opening_balance'">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
              Năm tài chính <span class="text-red-500">*</span>
            </label>
            <input v-model.number="form.fiscal_year" type="number" min="2020" max="2099"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="errors.fiscal_year" class="mt-1 text-xs text-red-600">{{ errors.fiscal_year }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
              {{ form.advance_type === 'prepayment' ? 'Ngày trả trước' : 'Ngày đầu kỳ' }}
              <span class="text-red-500">*</span>
            </label>
            <input v-model="form.opening_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="errors.opening_date" class="mt-1 text-xs text-red-600">{{ errors.opening_date }}</p>
          </div>
        </div>

        <!-- Số tiền -->
        <div class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">
            Số tiền (VND) <span class="text-red-500">*</span>
          </label>
          <input v-model.number="form.amount" type="number" min="1" step="1"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-primary-500"
            :disabled="isEdit && hasAllocations" />
          <p v-if="errors.amount" class="mt-1 text-xs text-red-600">{{ errors.amount }}</p>
          <p v-if="form.amount > 0" class="mt-1 text-xs text-gray-500">
            = {{ Number(form.amount).toLocaleString('vi-VN') }} đ
          </p>
          <p v-if="isEdit && hasAllocations" class="mt-1 text-xs text-amber-600">
            Không thể sửa số tiền khi đã có đối trừ.
          </p>
        </div>

        <!-- Mã tham chiếu + thông tin bổ sung cho opening_balance -->
        <div class="px-6 py-5 grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Mã tham chiếu</label>
            <input v-model="form.reference_no" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
              placeholder="Số phiếu / CK..." />
          </div>
          <div v-if="form.advance_type === 'opening_balance'">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Mã GD ngân hàng</label>
            <input v-model="form.bank_transaction_ref" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div v-if="form.advance_type === 'opening_balance'" class="px-6 py-5 grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Ngày chuyển khoản gốc</label>
            <input v-model="form.original_payment_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Diễn giải CK gốc</label>
            <input v-model="form.original_payment_note" type="text"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <!-- Ghi chú -->
        <div class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Ghi chú</label>
          <textarea v-model="form.notes" rows="2"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
        </div>

        <!-- Actions -->
        <div class="px-6 py-5 flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white px-5 py-2 rounded-lg text-sm font-medium">
            {{ form.processing ? 'Đang lưu...' : submitLabel }}
          </button>
          <Link :href="route('purchasing.supplier-advances.index')"
            class="bg-white border border-gray-300 hover:border-gray-400 text-gray-700 px-5 py-2 rounded-lg text-sm font-medium">
            Hủy
          </Link>
        </div>
      </form>

    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/Layout/AppLayout.vue'

const props = defineProps({
  advance:   Object,
  suppliers: Array,
  funds:     { type: Array, default: () => [] },
})

const isEdit = computed(() => !!props.advance?.id)
const hasAllocations = computed(() => (props.advance?.active_allocations_count ?? 0) > 0)

const form = useForm({
  advance_type:          props.advance?.advance_type ?? 'opening_balance',
  supplier_id:           props.advance?.supplier_id ?? '',
  fund_id:               '',
  payment_method:        'bank_transfer',
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

const pageTitle = computed(() => {
  if (isEdit.value) return 'Sửa khoản ứng trước'
  return form.advance_type === 'prepayment' ? 'Trả trước nhà cung cấp' : 'Ứng trước đầu kỳ'
})

const submitLabel = computed(() => {
  if (isEdit.value) return 'Cập nhật'
  return form.advance_type === 'prepayment' ? 'Tạo & ghi sổ phiếu chi' : 'Tạo khoản ứng trước'
})

function submit() {
  if (isEdit.value) {
    form.put(route('purchasing.supplier-advances.update', props.advance.id))
  } else {
    form.post(route('purchasing.supplier-advances.store'))
  }
}
</script>
