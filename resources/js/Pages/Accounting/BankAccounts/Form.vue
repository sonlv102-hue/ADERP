<template>
  <AppLayout :title="account ? 'Sửa tài khoản NH' : 'Thêm tài khoản NH'">
    <div class="max-w-xl mx-auto">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.bank-accounts.index')" class="text-gray-500 hover:text-gray-700">←</Link>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ account ? 'Sửa tài khoản ngân hàng' : 'Thêm tài khoản ngân hàng' }}
        </h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl shadow-sm p-6 space-y-5">
        <div class="grid grid-cols-2 gap-4">
          <div class="col-span-2">
            <label class="form-label">Tên tài khoản <span class="text-red-500">*</span></label>
            <input v-model="form.name" class="form-input" :class="{ 'border-red-500': form.errors.name }"
              placeholder="VD: Vietcombank HCM" />
            <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
          </div>

          <div>
            <label class="form-label">Ngân hàng <span class="text-red-500">*</span></label>
            <input v-model="form.bank_name" class="form-input" placeholder="Vietcombank" />
          </div>

          <div>
            <label class="form-label">Số tài khoản <span class="text-red-500">*</span></label>
            <input v-model="form.account_number" class="form-input font-mono" placeholder="0121234567890" />
          </div>

          <div>
            <label class="form-label">TK kế toán <span class="text-red-500">*</span></label>
            <select v-model="form.account_code" class="form-input">
              <option value="1121">1121 - Tiền gửi NH (VND)</option>
              <option value="1122">1122 - Tiền gửi NH (Ngoại tệ)</option>
              <option value="1123">1123 - Tiền gửi NH (Vàng tiền tệ)</option>
            </select>
          </div>

          <div>
            <label class="form-label">Số dư ban đầu (₫)</label>
            <input v-model.number="form.opening_balance" type="number" step="1000" class="form-input" />
          </div>
        </div>

        <div>
          <label class="form-label">Ghi chú</label>
          <textarea v-model="form.notes" rows="2" class="form-input" />
        </div>

        <div v-if="account" class="flex items-center gap-2">
          <input type="checkbox" id="is_active" v-model="form.is_active" class="rounded" />
          <label for="is_active" class="text-sm text-gray-700">Đang hoạt động</label>
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing" class="btn-primary">
            {{ form.processing ? 'Đang lưu...' : (account ? 'Cập nhật' : 'Tạo mới') }}
          </button>
          <Link :href="route('accounting.bank-accounts.index')" class="btn-secondary">Huỷ</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({ account: Object });

const form = useForm({
  name:            props.account?.name            ?? '',
  bank_name:       props.account?.bank_name       ?? '',
  account_number:  props.account?.account_number  ?? '',
  account_code:    props.account?.account_code    ?? '1121',
  opening_balance: props.account?.opening_balance ?? 0,
  is_active:       props.account?.is_active       ?? true,
  notes:           props.account?.notes           ?? '',
});

function submit() {
  if (props.account) {
    form.put(route('accounting.bank-accounts.update', props.account.id));
  } else {
    form.post(route('accounting.bank-accounts.store'));
  }
}
</script>
