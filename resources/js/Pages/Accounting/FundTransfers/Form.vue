<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.fund-transfers.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Tạo phiếu luân chuyển quỹ</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">

        <!-- Ngày chứng từ -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ngày luân chuyển <span class="text-red-500">*</span></label>
          <input v-model="form.transfer_date" type="date"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.transfer_date }" />
          <p v-if="form.errors.transfer_date" class="mt-1 text-xs text-red-600">{{ form.errors.transfer_date }}</p>
        </div>

        <!-- Quỹ nguồn / Quỹ đích -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ nguồn <span class="text-red-500">*</span></label>
            <SearchableSelect
              v-model="form.from_fund_id"
              :options="fromFundOptions"
              placeholder="-- Chọn quỹ nguồn --"
              :has-error="!!form.errors.from_fund_id"
            />
            <p v-if="fromFund" class="mt-1 text-xs text-gray-500">
              Số dư: <span :class="fromFund.balance < 0 ? 'text-red-600 font-semibold' : 'text-gray-700'">{{ formatVnd(fromFund.balance) }}</span>
            </p>
            <p v-if="form.errors.from_fund_id" class="mt-1 text-xs text-red-600">{{ form.errors.from_fund_id }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ đích <span class="text-red-500">*</span></label>
            <SearchableSelect
              v-model="form.to_fund_id"
              :options="toFundOptions"
              placeholder="-- Chọn quỹ đích --"
              :has-error="!!form.errors.to_fund_id"
            />
            <p v-if="form.errors.to_fund_id" class="mt-1 text-xs text-red-600">{{ form.errors.to_fund_id }}</p>
          </div>
        </div>

        <!-- Bút toán preview -->
        <div v-if="fromFund && toFund" class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-xs text-blue-800 space-y-1">
          <p class="font-semibold">Bút toán sẽ được sinh:</p>
          <p>Nợ {{ toFund.account_code || (toFund.type === 'bank' ? '1121' : '1111') }} — {{ toFund.name }}</p>
          <p>Có {{ fromFund.account_code || (fromFund.type === 'bank' ? '1121' : '1111') }} — {{ fromFund.name }}</p>
          <p v-if="!fromFund.account_code || !toFund.account_code" class="text-orange-700 mt-1">
            ⚠ Quỹ chưa có TK chi tiết — sẽ dùng TK mặc định hệ thống.
            Khuyến nghị cấu hình TK chi tiết trong Quản lý quỹ.
          </p>
        </div>

        <!-- Số tiền -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền <span class="text-red-500">*</span></label>
          <input v-model.number="form.amount" type="number" min="0.01" step="any"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': form.errors.amount }" />
          <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
        </div>

        <!-- Diễn giải -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải</label>
          <textarea v-model="form.description" rows="2"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            placeholder="Nội dung luân chuyển..." />
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : 'Tạo phiếu' }}
          </button>
          <Link :href="route('accounting.fund-transfers.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import SearchableSelect from '@/Components/Shared/SearchableSelect.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  funds:    Array,
  transfer: Object,
});

const { formatVnd } = useCurrency();

const form = useForm({
  transfer_date: new Date().toISOString().slice(0, 10),
  from_fund_id:  null,
  to_fund_id:    null,
  amount:        null,
  description:   '',
});

const fromFundOptions = computed(() =>
  (props.funds ?? [])
    .filter(f => f.id !== form.to_fund_id)
    .map(f => ({
      value: f.id,
      label: f.name,
      code: f.account_code || '',
      meta: f.type === 'bank' ? 'Ngân hàng' : 'Tiền mặt',
    }))
);
const toFundOptions = computed(() =>
  (props.funds ?? [])
    .filter(f => f.id !== form.from_fund_id)
    .map(f => ({
      value: f.id,
      label: f.name,
      code: f.account_code || '',
      meta: f.type === 'bank' ? 'Ngân hàng' : 'Tiền mặt',
    }))
);

const groupedFunds = computed(() => {
  const groups = { 'Tiền mặt': [], 'Ngân hàng': [] };
  for (const f of (props.funds ?? [])) {
    if (f.type === 'bank') groups['Ngân hàng'].push(f);
    else groups['Tiền mặt'].push(f);
  }
  return groups;
});

const fromFund = computed(() => props.funds?.find(f => f.id === form.from_fund_id) ?? null);
const toFund   = computed(() => props.funds?.find(f => f.id === form.to_fund_id)   ?? null);

function submit() {
  form.post(route('accounting.fund-transfers.store'));
}
</script>
