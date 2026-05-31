<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.cash-vouchers.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ voucher ? 'Sửa phiếu' : (form.type === 'receipt' ? 'Tạo phiếu thu' : 'Tạo phiếu chi') }}
        </h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã phiếu</label>
            <input :value="form.code" readonly
              class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-500" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Loại phiếu</label>
            <div class="flex gap-3 mt-1">
              <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" v-model="form.type" value="receipt" :disabled="!!voucher"
                  class="text-green-600" />
                <span class="text-sm font-medium text-green-700">Phiếu thu</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" v-model="form.type" value="payment" :disabled="!!voucher"
                  class="text-red-600" />
                <span class="text-sm font-medium text-red-700">Phiếu chi</span>
              </label>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ <span class="text-red-500">*</span></label>
            <select v-model="form.fund_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.fund_id }">
              <option value="">-- Chọn quỹ --</option>
              <option v-for="f in funds" :key="f.id" :value="f.id">
                {{ f.name }} ({{ f.type === 'cash' ? 'Tiền mặt' : 'Ngân hàng' }})
              </option>
            </select>
            <p v-if="form.errors.fund_id" class="mt-1 text-xs text-red-600">{{ form.errors.fund_id }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày <span class="text-red-500">*</span></label>
            <input v-model="form.voucher_date" type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.voucher_date }" />
            <p v-if="form.errors.voucher_date" class="mt-1 text-xs text-red-600">{{ form.errors.voucher_date }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền <span class="text-red-500">*</span></label>
            <input v-model.number="form.amount" type="number" min="1" step="1"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.amount }" />
            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Đối tác (tên người/đơn vị)</label>
            <input v-model="form.counterparty" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>

          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải <span class="text-red-500">*</span></label>
            <input v-model="form.description" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.description }" />
            <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('accounting.cash-vouchers.index')"
            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="px-5 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-lg disabled:opacity-50">
            {{ voucher ? 'Cập nhật' : 'Lưu phiếu' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  voucher:     Object,
  funds:       Array,
  nextCode:    String,
  defaultType: String,
});

const form = useForm({
  code:         props.voucher?.code         ?? props.nextCode,
  type:         props.voucher?.type         ?? props.defaultType ?? 'receipt',
  fund_id:      props.voucher?.fund_id      ?? '',
  amount:       props.voucher?.amount       ?? '',
  voucher_date: props.voucher?.voucher_date ?? new Date().toISOString().slice(0, 10),
  counterparty: props.voucher?.counterparty ?? '',
  description:  props.voucher?.description  ?? '',
});

function submit() {
  if (props.voucher) {
    form.put(route('accounting.cash-vouchers.update', props.voucher.id));
  } else {
    form.post(route('accounting.cash-vouchers.store'));
  }
}
</script>
