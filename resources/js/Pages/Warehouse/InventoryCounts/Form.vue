<template>
  <AppLayout>
    <div class="max-w-lg mx-auto space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Tạo phiếu kiểm kê</h1>
        <Link :href="route('warehouse.inventory-counts.index')"
          class="text-sm text-gray-500 hover:text-gray-700">← Danh sách</Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Kho kiểm kê <span class="text-red-500">*</span></label>
          <select v-model="form.warehouse_id"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="">-- Chọn kho --</option>
            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
          </select>
          <p v-if="form.errors.warehouse_id" class="text-red-500 text-xs mt-1">{{ form.errors.warehouse_id }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ngày kiểm kê <span class="text-red-500">*</span></label>
          <input v-model="form.count_date" type="date"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          <p v-if="form.errors.count_date" class="text-red-500 text-xs mt-1">{{ form.errors.count_date }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="3"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
            placeholder="Ghi chú..." />
        </div>

        <p class="text-xs text-gray-500 bg-blue-50 border border-blue-100 rounded-lg px-3 py-2">
          Hệ thống sẽ tự động tải danh sách sản phẩm đang có tồn kho tại kho đã chọn.
          Sau khi tạo, bạn điền số lượng thực đếm rồi xác nhận để điều chỉnh tồn kho.
        </p>

        <div class="flex gap-2 justify-end pt-2">
          <Link :href="route('warehouse.inventory-counts.index')"
            class="px-4 py-2 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
            Hủy
          </Link>
          <button @click="submit" :disabled="form.processing"
            class="px-4 py-2 text-sm rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-60">
            Tạo phiếu kiểm kê
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

defineProps({ warehouses: Array });

const form = useForm({
  warehouse_id: '',
  count_date: new Date().toISOString().slice(0, 10),
  notes: '',
});

function submit() {
  form.post(route('warehouse.inventory-counts.store'));
}
</script>
