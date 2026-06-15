<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Quản lý quỹ</h1>
        <div class="flex gap-2">
          <Link :href="route('accounting.fund-transfers.index')"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Luân chuyển quỹ
          </Link>
          <Link :href="route('accounting.funds.create')"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            + Thêm quỹ
          </Link>
        </div>
      </div>

      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.error }}
      </div>

      <!-- Cash funds -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
          <h2 class="font-semibold text-gray-700">Quỹ tiền mặt</h2>
        </div>
        <table class="w-full text-sm">
          <thead class="border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã / Tên quỹ</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số dư đầu kỳ</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số dư hiện tại</th>
              <th class="text-center px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="f in cashFunds" :key="f.id" class="hover:bg-gray-50">
              <td class="px-5 py-3">
                <p class="font-medium text-gray-800">{{ f.name }}</p>
                <p class="text-xs text-gray-400 font-mono">{{ f.code }}</p>
              </td>
              <td class="px-5 py-3 text-right text-gray-600">{{ formatVnd(f.opening_balance) }}</td>
              <td class="px-5 py-3 text-right font-semibold" :class="f.balance >= 0 ? 'text-gray-900' : 'text-red-600'">
                {{ formatVnd(f.balance) }}
              </td>
              <td class="px-5 py-3 text-center">
                <span :class="f.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                  class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium">
                  {{ f.is_active ? 'Hoạt động' : 'Tạm dừng' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('accounting.funds.edit', f.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium">Sửa</Link>
              </td>
            </tr>
            <tr v-if="!cashFunds.length">
              <td colspan="5" class="px-5 py-6 text-center text-gray-400 text-sm">Chưa có quỹ tiền mặt</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Bank accounts -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
          <h2 class="font-semibold text-gray-700">Tài khoản ngân hàng</h2>
        </div>
        <table class="w-full text-sm">
          <thead class="border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên tài khoản</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngân hàng / Số TK</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số dư đầu kỳ</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số dư hiện tại</th>
              <th class="text-center px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="f in bankFunds" :key="f.id" class="hover:bg-gray-50">
              <td class="px-5 py-3">
                <p class="font-medium text-gray-800">{{ f.name }}</p>
                <p class="text-xs text-gray-400 font-mono">{{ f.code }}</p>
              </td>
              <td class="px-5 py-3 text-gray-600">
                <p>{{ f.bank_name }}</p>
                <p class="text-xs font-mono text-gray-400">{{ f.bank_account_no }}</p>
              </td>
              <td class="px-5 py-3 text-right text-gray-600">{{ formatVnd(f.opening_balance) }}</td>
              <td class="px-5 py-3 text-right font-semibold" :class="f.balance >= 0 ? 'text-gray-900' : 'text-red-600'">
                {{ formatVnd(f.balance) }}
              </td>
              <td class="px-5 py-3 text-center">
                <span :class="f.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                  class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium">
                  {{ f.is_active ? 'Hoạt động' : 'Tạm dừng' }}
                </span>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('accounting.funds.edit', f.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium">Sửa</Link>
              </td>
            </tr>
            <tr v-if="!bankFunds.length">
              <td colspan="6" class="px-5 py-6 text-center text-gray-400 text-sm">Chưa có tài khoản ngân hàng</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ funds: Array });
const { formatVnd } = useCurrency();

const cashFunds = computed(() => props.funds.filter(f => f.type === 'cash'));
const bankFunds = computed(() => props.funds.filter(f => f.type === 'bank'));
</script>
