<template>
  <AppLayout :title="pageTitle">
    <div class="max-w-2xl mx-auto space-y-5">

      <div>
        <nav class="flex items-center gap-2 text-sm text-gray-500 mb-1">
          <Link :href="route('sales.customer-advances.index')" class="hover:text-primary-600">
            Ứng trước khách hàng
          </Link>
          <span>/</span>
          <span class="text-gray-700">Thêm mới</span>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900">{{ pageTitle }}</h1>
      </div>

      <!-- Info note -->
      <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex gap-3">
        <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <p class="text-sm text-blue-800" v-if="form.advance_type === 'advance_receipt'">
          <strong>Nhận ứng trước trong kỳ:</strong> Hệ thống tạo Phiếu Thu (Dr Quỹ / Cr 131UT).
          Khi đối trừ vào hóa đơn sau, bút toán Dr 131UT / Cr 1311 sẽ được tạo tự động.
        </p>
        <p class="text-sm text-blue-800" v-else>
          <strong>Số dư đầu kỳ:</strong> Khách hàng đã ứng trước từ kỳ trước chưa có hóa đơn.
          Không tạo phiếu thu — chỉ ghi nhận tồn đầu kỳ.
        </p>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">

        <!-- Loại ứng trước -->
        <div class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-2">Loại khoản ứng trước</label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" v-model="form.advance_type" value="opening_balance" class="text-primary-600" />
              <span class="text-sm">Số dư đầu kỳ (không tạo phiếu thu)</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" v-model="form.advance_type" value="advance_receipt" class="text-primary-600" />
              <span class="text-sm">Nhận ứng trước trong kỳ (tạo phiếu thu Dr Quỹ / Cr 131UT)</span>
            </label>
          </div>
        </div>

        <!-- Khách hàng -->
        <div class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">
            Khách hàng <span class="text-red-500">*</span>
          </label>
          <RemoteSearchSelect
            v-model="form.customer_id"
            :display-text="form.customer_name"
            :search-url="route('search.customers')"
            placeholder="Tìm theo tên, mã KH, MST..."
            :has-error="!!form.errors.customer_id"
            @change="(opt) => form.customer_name = opt ? opt.label : ''"
          />
          <p v-if="errors.customer_id" class="mt-1 text-xs text-red-600">{{ errors.customer_id }}</p>
        </div>

        <!-- Quỹ/Ngân hàng (chỉ cho advance_receipt) -->
        <div v-if="form.advance_type === 'advance_receipt'" class="px-6 py-5 grid grid-cols-2 gap-4">
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
              {{ form.advance_type === 'advance_receipt' ? 'Ngày nhận ứng trước' : 'Ngày đầu kỳ' }}
              <span class="text-red-500">*</span>
            </label>
            <input v-model="form.advance_date" type="date"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="errors.advance_date" class="mt-1 text-xs text-red-600">{{ errors.advance_date }}</p>
          </div>
        </div>

        <!-- Số tiền -->
        <div class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">
            Số tiền (VND) <span class="text-red-500">*</span>
          </label>
          <input v-model.number="form.amount" type="number" min="1" step="1"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-primary-500" />
          <p v-if="errors.amount" class="mt-1 text-xs text-red-600">{{ errors.amount }}</p>
          <p v-if="form.amount > 0" class="mt-1 text-xs text-gray-500">
            = {{ Number(form.amount).toLocaleString('vi-VN') }} đ
          </p>
        </div>

        <!-- Tham chiếu + Ghi chú -->
        <div class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Mã tham chiếu</label>
          <input v-model="form.reference_no" type="text"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
            placeholder="Số phiếu / CK..." />
        </div>

        <div class="px-6 py-5">
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Ghi chú</label>
          <textarea v-model="form.notes" rows="2"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
        </div>

        <div class="px-6 py-5 flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white px-5 py-2 rounded-lg text-sm font-medium">
            {{ form.processing ? 'Đang lưu...' : submitLabel }}
          </button>
          <Link :href="route('sales.customer-advances.index')"
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
  customers: { type: Array, default: () => [] },
  funds:     { type: Array, default: () => [] },
})

const form = useForm({
  advance_type:   'opening_balance',
  customer_id:    '',
  customer_name:  '',
  fund_id:        '',
  payment_method: 'bank_transfer',
  fiscal_year:    new Date().getFullYear(),
  advance_date:   '',
  amount:         '',
  reference_no:   '',
  notes:          '',
})

const errors = computed(() => form.errors)

const pageTitle = computed(() =>
  form.advance_type === 'advance_receipt' ? 'Nhận ứng trước khách hàng' : 'Ứng trước đầu kỳ'
)

const submitLabel = computed(() =>
  form.advance_type === 'advance_receipt' ? 'Tạo & ghi sổ phiếu thu' : 'Tạo khoản ứng trước'
)

function submit() {
  form.post(route('sales.customer-advances.store'))
}
</script>
