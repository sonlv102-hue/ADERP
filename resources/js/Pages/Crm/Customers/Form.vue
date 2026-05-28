<template>
  <AppLayout>
    <div class="max-w-3xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('crm.customers.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ customer ? 'Sửa khách hàng' : 'Thêm khách hàng mới' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã khách hàng <span class="text-red-500">*</span></label>
            <input v-model="form.code" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.code }" />
            <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên khách hàng <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Công ty</label>
            <input v-model="form.company" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.company }" />
            <p v-if="form.errors.company" class="mt-1 text-xs text-red-600">{{ form.errors.company }}</p>
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

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Trạng thái <span class="text-red-500">*</span></label>
            <select v-model="form.lead_status"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.lead_status }">
              <option value="">-- Chọn trạng thái --</option>
              <option v-for="s in lead_statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <p v-if="form.errors.lead_status" class="mt-1 text-xs text-red-600">{{ form.errors.lead_status }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Phụ trách</label>
            <select v-model="form.assigned_to"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.assigned_to }">
              <option :value="null">-- Không có --</option>
              <option v-for="user in sales_users" :key="user.id" :value="user.id">{{ user.name }}</option>
            </select>
            <p v-if="form.errors.assigned_to" class="mt-1 text-xs text-red-600">{{ form.errors.assigned_to }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Điều khoản thanh toán</label>
            <select v-model="form.payment_term_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
              <option :value="null">-- Không có --</option>
              <option v-for="pt in payment_terms" :key="pt.id" :value="pt.id">{{ pt.name }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hạn mức tín dụng (₫)</label>
            <input v-model.number="form.credit_limit" type="number" min="0" step="1000000"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              placeholder="0 = không giới hạn" />
            <p class="mt-1 text-xs text-gray-400">0 hoặc bỏ trống = không giới hạn</p>
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
          <textarea v-model="form.notes" rows="3"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.notes }" />
          <p v-if="form.errors.notes" class="mt-1 text-xs text-red-600">{{ form.errors.notes }}</p>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (customer ? 'Cập nhật' : 'Thêm khách hàng') }}
          </button>
          <Link :href="route('crm.customers.index')"
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
  customer: { type: Object, default: null },
  nextCode: String,
  lead_statuses: Array,
  sales_users: Array,
  payment_terms: Array,
});

const form = useForm({
  code:            props.customer?.code            ?? props.nextCode ?? '',
  name:            props.customer?.name            ?? '',
  company:         props.customer?.company         ?? '',
  tax_code:        props.customer?.tax_code        ?? '',
  phone:           props.customer?.phone           ?? '',
  email:           props.customer?.email           ?? '',
  lead_status:     props.customer?.lead_status     ?? '',
  assigned_to:     props.customer?.assigned_to     ?? null,
  address:         props.customer?.address         ?? '',
  notes:           props.customer?.notes           ?? '',
  payment_term_id: props.customer?.payment_term_id ?? null,
  credit_limit:    props.customer?.credit_limit    ?? null,
});

const submit = () => {
  if (props.customer) {
    form.put(route('crm.customers.update', props.customer.id));
  } else {
    form.post(route('crm.customers.store'));
  }
};
</script>
