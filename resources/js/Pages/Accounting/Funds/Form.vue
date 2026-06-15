<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.funds.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ fund ? 'Sửa quỹ' : 'Thêm quỹ' }}</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã quỹ <span class="text-red-500">*</span></label>
            <input v-model="form.code" type="text" :readonly="!!fund"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none disabled:bg-gray-50"
              :class="{ 'bg-gray-50 text-gray-500': !!fund, 'border-red-500': form.errors.code }" />
            <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Loại quỹ <span class="text-red-500">*</span></label>
            <select v-model="form.type"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.type }">
              <option value="cash">Tiền mặt</option>
              <option value="bank">Ngân hàng</option>
            </select>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên quỹ / Tên tài khoản <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
          </div>

          <template v-if="form.type === 'bank'">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngân hàng</label>
              <input v-model="form.bank_name" type="text" placeholder="VD: Vietcombank"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Số tài khoản</label>
              <input v-model="form.bank_account_no" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
          </template>

          <!-- TK kế toán chi tiết cho quỹ này -->
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Tài khoản kế toán</label>
            <select v-model="form.account_code"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
              <option :value="null">-- Dùng mặc định hệ thống (1111 / 1121) --</option>
              <option v-for="a in accounts" :key="a.code" :value="a.code">{{ a.code }} — {{ a.name }}</option>
            </select>
            <p class="mt-1 text-xs text-gray-400">
              Cấu hình TK chi tiết để sinh bút toán đúng khi luân chuyển quỹ
              (VD: 1111 — TM VND, 1121 — TGNH Vietcombank).
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số dư đầu kỳ</label>
            <input v-model.number="form.opening_balance" type="number" min="0" step="any"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>

          <div v-if="fund" class="flex items-center gap-2">
            <input id="is_active" v-model="form.is_active" type="checkbox"
              class="w-4 h-4 text-primary-600 rounded border-gray-300" />
            <label for="is_active" class="text-sm font-medium text-gray-700">Đang hoạt động</label>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('accounting.funds.index')"
            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="px-5 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-lg disabled:opacity-50">
            {{ fund ? 'Cập nhật' : 'Tạo quỹ' }}
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
  fund:     Object,
  nextCode: String,
  accounts: { type: Array, default: () => [] },
});

const form = useForm({
  code:            props.fund?.code         ?? props.nextCode,
  name:            props.fund?.name         ?? '',
  type:            props.fund?.type         ?? 'cash',
  account_code:    props.fund?.account_code ?? null,
  bank_name:       props.fund?.bank_name    ?? '',
  bank_account_no: props.fund?.bank_account_no ?? '',
  opening_balance: props.fund?.opening_balance ?? 0,
  is_active:       props.fund?.is_active    ?? true,
  notes:           props.fund?.notes        ?? '',
});

function submit() {
  if (props.fund) {
    form.put(route('accounting.funds.update', props.fund.id));
  } else {
    form.post(route('accounting.funds.store'));
  }
}
</script>
