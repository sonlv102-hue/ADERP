<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('warehouse.suppliers.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ supplier ? 'Sửa nhà cung cấp' : 'Thêm nhà cung cấp mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- Thông tin cơ bản -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Thông tin cơ bản</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã nhà cung cấp</label>
              <input v-model="form.code" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.code }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên nhà cung cấp <span class="text-red-500">*</span></label>
              <input v-model="form.name" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.name }" />
              <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã số thuế</label>
              <input v-model="form.tax_code" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.tax_code }" />
              <p v-if="form.errors.tax_code" class="mt-1 text-xs text-red-600">{{ form.errors.tax_code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
              <input v-model="form.phone" type="tel"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.phone }" />
              <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
              <input v-model="form.email" type="email"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.email }" />
              <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Địa chỉ</label>
            <textarea v-model="form.address" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.address }" />
            <p v-if="form.errors.address" class="mt-1 text-xs text-red-600">{{ form.errors.address }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.notes }" />
            <p v-if="form.errors.notes" class="mt-1 text-xs text-red-600">{{ form.errors.notes }}</p>
          </div>

          <div v-if="supplier" class="flex items-center gap-2">
            <input v-model="form.is_active" id="is_active" type="checkbox"
              class="h-4 w-4 text-primary-600 rounded border-gray-300" />
            <label for="is_active" class="text-sm text-gray-700">Nhà cung cấp đang hoạt động</label>
          </div>
        </div>

        <!-- Điều khoản thanh toán -->
        <div class="bg-white rounded-xl border border-gray-200 p-6">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Điều khoản</h2>
          <div class="max-w-xs">
            <label class="block text-sm font-medium text-gray-700 mb-1">Điều khoản thanh toán</label>
            <select v-model="form.payment_term_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
              <option :value="null">-- Không có --</option>
              <option v-for="pt in payment_terms" :key="pt.id" :value="pt.id">{{ pt.name }}</option>
            </select>
          </div>
        </div>

        <!-- Thông tin ngân hàng -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Tài khoản ngân hàng</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên ngân hàng</label>
              <input v-model="form.bank_name" type="text" placeholder="VD: Vietcombank, Techcombank..."
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.bank_name }" />
              <p v-if="form.errors.bank_name" class="mt-1 text-xs text-red-600">{{ form.errors.bank_name }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số tài khoản</label>
              <input v-model="form.bank_account" type="text" placeholder="VD: 1234567890"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none font-mono"
                :class="{ 'border-red-500': form.errors.bank_account }" />
              <p v-if="form.errors.bank_account" class="mt-1 text-xs text-red-600">{{ form.errors.bank_account }}</p>
            </div>

            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên chủ tài khoản</label>
              <input v-model="form.bank_account_name" type="text" placeholder="VD: CONG TY TNHH ABC"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none uppercase"
                :class="{ 'border-red-500': form.errors.bank_account_name }" />
              <p v-if="form.errors.bank_account_name" class="mt-1 text-xs text-red-600">{{ form.errors.bank_account_name }}</p>
            </div>

            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Chi nhánh ngân hàng</label>
              <input v-model="form.bank_branch" type="text" placeholder="VD: Chi nhánh TP.HCM"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.bank_branch }" />
              <p v-if="form.errors.bank_branch" class="mt-1 text-xs text-red-600">{{ form.errors.bank_branch }}</p>
            </div>
          </div>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (supplier ? 'Cập nhật' : 'Thêm nhà cung cấp') }}
          </button>
          <Link :href="route('warehouse.suppliers.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  supplier:      { type: Object, default: null },
  nextCode:      String,
  payment_terms: { type: Array, default: () => [] },
});

const form = useForm({
  code:              props.supplier?.code              ?? props.nextCode ?? '',
  name:              props.supplier?.name              ?? '',
  tax_code:          props.supplier?.tax_code          ?? '',
  phone:             props.supplier?.phone             ?? '',
  email:             props.supplier?.email             ?? '',
  address:           props.supplier?.address           ?? '',
  bank_name:         props.supplier?.bank_name         ?? '',
  bank_account:      props.supplier?.bank_account      ?? '',
  bank_account_name: props.supplier?.bank_account_name ?? '',
  bank_branch:       props.supplier?.bank_branch       ?? '',
  notes:             props.supplier?.notes             ?? '',
  is_active:         props.supplier?.is_active         ?? true,
  payment_term_id:   props.supplier?.payment_term_id   ?? null,
});

const submit = () => {
  if (props.supplier) {
    form.put(route('warehouse.suppliers.update', props.supplier.id));
  } else {
    form.post(route('warehouse.suppliers.store'));
  }
};
</script>
