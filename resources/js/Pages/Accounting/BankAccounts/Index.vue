<template>
  <AppLayout title="Tài khoản ngân hàng">
    <div class="max-w-5xl mx-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Tài khoản ngân hàng</h1>
        <Link v-if="can('accounting.manage')" :href="route('accounting.bank-accounts.create')"
          class="btn-primary">+ Thêm tài khoản</Link>
      </div>

      <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
            <tr>
              <th class="px-4 py-3 text-left">Tên TK</th>
              <th class="px-4 py-3 text-left">Ngân hàng</th>
              <th class="px-4 py-3 text-left">Số TK</th>
              <th class="px-4 py-3 text-center">TK kế toán</th>
              <th class="px-4 py-3 text-right">Số dư hiện tại</th>
              <th class="px-4 py-3 text-center">Trạng thái</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="acc in accounts" :key="acc.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 font-medium text-gray-900">{{ acc.name }}</td>
              <td class="px-4 py-3 text-gray-600">{{ acc.bank_name }}</td>
              <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ acc.account_number }}</td>
              <td class="px-4 py-3 text-center font-mono text-xs font-semibold text-primary-600">{{ acc.account_code }}</td>
              <td class="px-4 py-3 text-right font-medium"
                :class="acc.current_balance >= 0 ? 'text-green-600' : 'text-red-600'">
                {{ formatVnd(acc.current_balance) }}
              </td>
              <td class="px-4 py-3 text-center">
                <span :class="acc.is_active ? 'badge-green' : 'badge-gray'">
                  {{ acc.is_active ? 'Hoạt động' : 'Tạm dừng' }}
                </span>
              </td>
              <td class="px-4 py-3 text-right flex gap-3 justify-end">
                <Link :href="route('accounting.bank-accounts.transactions.index', acc.id)"
                  class="text-sm text-blue-600 hover:underline">Giao dịch</Link>
                <Link v-if="can('accounting.manage')"
                  :href="route('accounting.bank-accounts.edit', acc.id)"
                  class="text-sm text-primary-600 hover:underline">Sửa</Link>
              </td>
            </tr>
            <tr v-if="accounts.length === 0">
              <td colspan="7" class="px-4 py-10 text-center text-gray-400">Chưa có tài khoản ngân hàng</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { usePermission } from '@/composables/usePermission';

const { hasPermission: can } = usePermission();
defineProps({ accounts: Array });

function formatVnd(val) {
  return new Intl.NumberFormat('vi-VN').format(val || 0) + ' ₫';
}
</script>
