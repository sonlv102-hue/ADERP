<template>
  <AppLayout title="Bảng Chấm Công">
    <div class="max-w-5xl mx-auto py-6 px-4">

      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Bảng Chấm Công</h1>
          <p class="text-sm text-gray-500 mt-1">Quản lý chấm công hàng tháng</p>
        </div>
        <button @click="showCreate = true"
          class="inline-flex items-center gap-2 px-4 py-2 bg-primary-600 text-white rounded-lg text-sm font-medium hover:bg-primary-700">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          Tạo bảng mới
        </button>
      </div>

      <!-- Flash -->
      <div v-if="$page.props.flash?.success" class="mb-4 bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error"   class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.error }}</div>

      <!-- Table -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
            <tr>
              <th class="px-4 py-3 text-left">Mã bảng</th>
              <th class="px-4 py-3 text-left">Tháng</th>
              <th class="px-4 py-3 text-center">Số NV</th>
              <th class="px-4 py-3 text-center">Trạng thái</th>
              <th class="px-4 py-3 text-left">Người lập</th>
              <th class="px-4 py-3 text-left">Ngày tạo</th>
              <th class="px-4 py-3 text-right">Thao tác</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="s in sheets.data" :key="s.id" class="hover:bg-gray-50">
              <td class="px-4 py-3 font-mono font-semibold text-primary-700">{{ s.code }}</td>
              <td class="px-4 py-3 font-medium">{{ formatPeriod(s.period) }}</td>
              <td class="px-4 py-3 text-center">{{ s.employee_count }}</td>
              <td class="px-4 py-3 text-center">
                <StatusBadge :color="s.status_color">{{ s.status_label }}</StatusBadge>
              </td>
              <td class="px-4 py-3 text-gray-600">{{ s.creator }}</td>
              <td class="px-4 py-3 text-gray-500">{{ s.created_at }}</td>
              <td class="px-4 py-3 text-right">
                <Link :href="route('admin.attendance.show', s.id)"
                  class="text-primary-600 hover:underline text-sm font-medium mr-3">
                  Xem / Chấm
                </Link>
                <button v-if="s.status === 'draft'"
                  @click="confirmDelete(s)"
                  class="text-red-500 hover:underline text-sm">Xóa</button>
              </td>
            </tr>
            <tr v-if="!sheets.data.length">
              <td colspan="7" class="px-4 py-10 text-center text-gray-400">Chưa có bảng chấm công</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="sheets.links" class="mt-4" />

      <!-- Create Modal -->
      <Teleport to="body">
        <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Tạo bảng chấm công</h2>
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tháng <span class="text-red-500">*</span></label>
                <input v-model="form.period" type="month"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
                <p v-if="form.errors.period" class="text-red-500 text-xs mt-1">{{ form.errors.period }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
                <textarea v-model="form.notes" rows="2"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
                  placeholder="Ghi chú (nếu có)"></textarea>
              </div>
            </div>
            <div class="flex justify-end gap-3 mt-5">
              <button @click="showCreate = false; form.reset()"
                class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
              <button @click="submitCreate" :disabled="form.processing"
                class="px-4 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50">
                {{ form.processing ? 'Đang tạo...' : 'Tạo bảng' }}
              </button>
            </div>
          </div>
        </div>
      </Teleport>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

const props = defineProps({ sheets: Object });

const showCreate = ref(false);
const form = useForm({ period: '', notes: '' });

function formatPeriod(period) {
  const [y, m] = period.split('-');
  return `Tháng ${parseInt(m)}/${y}`;
}

function submitCreate() {
  form.post(route('admin.attendance.store'), {
    onSuccess: () => { showCreate.value = false; form.reset(); },
  });
}

function confirmDelete(s) {
  if (confirm(`Xóa bảng chấm công ${s.code}?`)) {
    router.delete(route('admin.attendance.destroy', s.id));
  }
}
</script>
