<template>
  <AppLayout>
    <div class="max-w-4xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('catalog.price-lists.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Sửa bảng giá' : 'Tạo bảng giá' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <!-- Header -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div v-if="isEdit">
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã bảng giá</label>
              <input :value="priceList.code" type="text" readonly
                class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 outline-none" />
            </div>

            <div :class="isEdit ? '' : 'sm:col-span-2'">
              <label class="block text-sm font-medium text-gray-700 mb-1">Tên bảng giá <span class="text-red-500">*</span></label>
              <input v-model="form.name" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.name }" />
              <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Hiệu lực từ</label>
              <input v-model="form.valid_from" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Hiệu lực đến</label>
              <input v-model="form.valid_to" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
          </div>

          <div class="flex items-center gap-3">
            <input v-model="form.is_default" type="checkbox" id="is_default"
              class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500" />
            <label for="is_default" class="text-sm font-medium text-gray-700">Đặt làm bảng giá mặc định</label>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>
        </div>

        <!-- Items -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-800">Danh sách sản phẩm</h2>
            <button type="button" @click="addRow"
              class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
              + Thêm sản phẩm
            </button>
          </div>

          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-3 font-semibold text-gray-600">Sản phẩm</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-24">ĐVT</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-36">Đơn giá</th>
                <th class="w-10 px-4 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(item, idx) in form.items" :key="idx">
                <td class="px-4 py-3">
                  <select v-model="item.product_id" @change="onProductChange(idx)"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-xs">
                    <option value="">-- Chọn sản phẩm --</option>
                    <option v-for="p in availableProducts(idx)" :key="p.id" :value="p.id">
                      {{ p.code }} - {{ p.name }}
                    </option>
                  </select>
                </td>
                <td class="px-4 py-3">
                  <input :value="unitOf(item.product_id)" type="text" readonly
                    class="w-full px-2 py-1.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 outline-none text-xs" />
                </td>
                <td class="px-4 py-3">
                  <input v-model.number="item.unit_price" type="number" min="0"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-xs" />
                </td>
                <td class="px-4 py-3 text-center">
                  <button type="button" @click="removeRow(idx)" class="text-red-500 hover:text-red-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </td>
              </tr>
              <tr v-if="!form.items.length">
                <td colspan="4" class="px-5 py-8 text-center text-gray-400">Chưa có sản phẩm. Nhấn "+ Thêm sản phẩm".</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (isEdit ? 'Cập nhật' : 'Tạo bảng giá') }}
          </button>
          <Link :href="route('catalog.price-lists.index')"
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
  priceList: Object,
  nextCode: String,
  products: Array,
});

const isEdit = !!props.priceList;

const form = useForm({
  name:       props.priceList?.name ?? '',
  valid_from: props.priceList?.valid_from ?? '',
  valid_to:   props.priceList?.valid_to ?? '',
  is_default: props.priceList?.is_default ?? false,
  notes:      props.priceList?.notes ?? '',
  items:      props.priceList?.items?.map(i => ({ product_id: i.product_id, unit_price: Number(i.unit_price) })) ?? [],
});

const addRow = () => {
  form.items.push({ product_id: '', unit_price: 0 });
};

const removeRow = (idx) => form.items.splice(idx, 1);

const onProductChange = (idx) => {
  const item = form.items[idx];
  const p = props.products.find(p => p.id === item.product_id);
  if (p) item.unit_price = Number(p.sell_price ?? 0);
};

const unitOf = (productId) => {
  return props.products.find(p => p.id === productId)?.unit ?? '';
};

// Exclude already-selected products except current row
const availableProducts = (currentIdx) => {
  const selected = form.items
    .map((i, idx) => idx !== currentIdx ? i.product_id : null)
    .filter(Boolean);
  return props.products.filter(p => !selected.includes(p.id));
};

const submit = () => {
  if (isEdit) {
    form.put(route('catalog.price-lists.update', props.priceList.id));
  } else {
    form.post(route('catalog.price-lists.store'));
  }
};
</script>
