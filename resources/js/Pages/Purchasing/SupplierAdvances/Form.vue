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
            <label class="inline-flex items-center gap-2 cursor-pointer">
              <input type="radio" v-model="form.advance_type" value="prepayment" class="text-primary-600" />
              <span class="text-sm">Trả trước trong kỳ (Chờ thanh toán)</span>
            </label>
          </div>
        </div>

        <!-- Nhà cung cấp -->
        <div class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">
            Nhà cung cấp <span class="text-red-500">*</span>
          </label>
          <RemoteSearchSelect
            v-model="form.supplier_id"
            :display-text="form.supplier_name"
            :search-url="route('search.suppliers')"
            placeholder="Tìm theo tên, mã NCC, MST..."
            :disabled="isLocked"
            :has-error="!!form.errors.supplier_id"
            @change="onSupplierChange"
          />
          <p v-if="errors.supplier_id" class="mt-1 text-xs text-red-600">{{ errors.supplier_id }}</p>
        </div>

        <!-- Hợp đồng mua & Đơn mua hàng -->
        <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Hợp đồng mua</label>
            <RemoteSearchSelect
              v-model="form.purchase_contract_id"
              :display-text="form.purchase_contract_code"
              :search-url="purchaseContractSearchUrl"
              placeholder="Tìm kiếm hợp đồng..."
              :disabled="isLocked"
              @change="onContractChange"
            />
            <p v-if="errors.purchase_contract_id" class="mt-1 text-xs text-red-600">{{ errors.purchase_contract_id }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Đơn mua hàng</label>
            <RemoteSearchSelect
              v-model="form.purchase_order_id"
              :display-text="form.purchase_order_code"
              :search-url="purchaseOrderSearchUrl"
              placeholder="Tìm kiếm đơn mua..."
              :disabled="isLocked"
              @change="onPOChange"
            />
            <p v-if="errors.purchase_order_id" class="mt-1 text-xs text-red-600">{{ errors.purchase_order_id }}</p>
          </div>
        </div>



        <!-- Năm + Ngày -->
        <div class="px-6 py-5 grid grid-cols-2 gap-4">
          <div v-if="form.advance_type === 'opening_balance'">
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
              Năm tài chính <span class="text-red-500">*</span>
            </label>
            <input v-model.number="form.fiscal_year" type="number" min="2020" max="2099"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
              :disabled="isLocked" />
            <p v-if="errors.fiscal_year" class="mt-1 text-xs text-red-600">{{ errors.fiscal_year }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
              {{ form.advance_type === 'prepayment' ? 'Ngày trả trước' : 'Ngày đầu kỳ' }}
              <span class="text-red-500">*</span>
            </label>
             <input v-model="form.opening_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
              :disabled="isLocked" />
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
            :disabled="isLocked" />
          <p v-if="errors.amount" class="mt-1 text-xs text-red-600">{{ errors.amount }}</p>
          <p v-if="form.amount > 0" class="mt-1 text-xs text-gray-500">
            = {{ Number(form.amount).toLocaleString('vi-VN') }} đ
          </p>
          <p v-if="isLocked" class="mt-1 text-xs text-amber-600">
            Không thể sửa các thông tin kế toán cốt lõi sau khi đã thanh toán/ứng trước.
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
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue'

const props = defineProps({
  advance:   Object,
  suppliers: { type: Array, default: () => [] },
  funds:     { type: Array, default: () => [] },
})

const isEdit = computed(() => !!props.advance?.id)
const hasAllocations = computed(() => (props.advance?.active_allocations_count ?? 0) > 0)

const isLocked = computed(() => {
  if (!isEdit.value) return false
  if (props.advance?.advance_type === 'prepayment' && props.advance?.status !== 'unpaid') {
    return true
  }
  if (props.advance?.advance_type === 'opening_balance' && hasAllocations.value) {
    return true
  }
  return false
})

const form = useForm({
  advance_type:           props.advance?.advance_type ?? 'opening_balance',
  supplier_id:            props.advance?.supplier_id ?? '',
  supplier_name:          props.advance?.supplier?.name ?? '',
  purchase_contract_id:   props.advance?.purchase_contract_id ?? '',
  purchase_contract_code: props.advance?.purchase_contract?.code ?? '',
  purchase_order_id:      props.advance?.purchase_order_id ?? '',
  purchase_order_code:    props.advance?.purchase_order?.code ?? '',
  fiscal_year:            props.advance?.fiscal_year ?? new Date().getFullYear(),
  opening_date:           props.advance?.opening_date ?? '',
  amount:                 props.advance?.amount ?? '',
  reference_no:           props.advance?.reference_no ?? '',
  bank_transaction_ref:   props.advance?.bank_transaction_ref ?? '',
  original_payment_date:  props.advance?.original_payment_date ?? '',
  original_payment_note:  props.advance?.original_payment_note ?? '',
  notes:                  props.advance?.notes ?? '',
})

const errors = computed(() => form.errors)

const purchaseContractSearchUrl = computed(() => {
  return route('search.purchase-contracts') + (form.supplier_id ? '?supplier_id=' + form.supplier_id : '')
})

const purchaseOrderSearchUrl = computed(() => {
  const params = new URLSearchParams()
  if (form.supplier_id) params.set('supplier_id', form.supplier_id)
  if (form.purchase_contract_id) params.set('purchase_contract_id', form.purchase_contract_id)
  return route('search.purchase-orders') + '?' + params.toString()
})

function onSupplierChange(opt) {
  form.supplier_name = opt ? opt.label : ''
  form.purchase_contract_id = ''
  form.purchase_contract_code = ''
  form.purchase_order_id = ''
  form.purchase_order_code = ''
}

function onContractChange(opt) {
  form.purchase_contract_code = opt ? opt.code : ''
  form.purchase_order_id = ''
  form.purchase_order_code = ''
  
  if (opt && opt.purchase_order_id) {
    form.purchase_order_id = opt.purchase_order_id
    form.purchase_order_code = opt.purchase_order_code || ''
  }
}

function onPOChange(opt) {
  form.purchase_order_code = opt ? opt.code : ''
  if (opt && opt.purchase_contract_id) {
    form.purchase_contract_id = opt.purchase_contract_id
    form.purchase_contract_code = opt.purchase_contract_code || ''
  }
}

const pageTitle = computed(() => {
  if (isEdit.value) return 'Sửa khoản ứng trước'
  return form.advance_type === 'prepayment' ? 'Trả trước nhà cung cấp' : 'Ứng trước đầu kỳ'
})

const submitLabel = computed(() => {
  if (isEdit.value) return 'Cập nhật thông tin'
  return form.advance_type === 'prepayment' ? 'Tạo khoản trả trước' : 'Tạo khoản ứng trước'
})

function submit() {
  if (isEdit.value) {
    form.put(route('purchasing.supplier-advances.update', props.advance.id))
  } else {
    form.post(route('purchasing.supplier-advances.store'))
  }
}
</script>
