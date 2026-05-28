<template>
  <AppLayout title="Điều khoản thanh toán">
    <div class="max-w-4xl mx-auto">
      <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Điều khoản thanh toán</h1>
        <Link v-if="can('accounting.manage')" :href="route('accounting.payment-terms.create')"
          class="btn-primary">
          + Thêm mới
        </Link>
      </div>

      <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
            <tr>
              <th class="px-4 py-3 text-left">Mã</th>
              <th class="px-4 py-3 text-left">Tên</th>
              <th class="px-4 py-3 text-right">Số ngày</th>
              <th class="px-4 py-3 text-left">Mô tả</th>
              <th class="px-4 py-3 text-center">Trạng thái</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="term in terms" :key="term.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 font-mono font-semibold text-primary-600">{{ term.code }}</td>
              <td class="px-4 py-3 font-medium">{{ term.name }}</td>
              <td class="px-4 py-3 text-right">{{ term.days }} ngày</td>
              <td class="px-4 py-3 text-gray-500">{{ term.description ?? '—' }}</td>
              <td class="px-4 py-3 text-center">
                <span :class="term.is_active ? 'badge-green' : 'badge-gray'">
                  {{ term.is_active ? 'Hoạt động' : 'Tạm dừng' }}
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <Link v-if="can('accounting.manage')"
                  :href="route('accounting.payment-terms.edit', term.id)"
                  class="text-sm text-primary-600 hover:underline">Sửa</Link>
              </td>
            </tr>
            <tr v-if="terms.length === 0">
              <td colspan="6" class="px-4 py-8 text-center text-gray-400">Chưa có dữ liệu</td>
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
defineProps({ terms: Array });
</script>
