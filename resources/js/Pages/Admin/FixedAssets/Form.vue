<template>
  <AppLayout>
    <div class="max-w-2xl mx-auto space-y-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ asset ? 'Cập nhật' : 'Thêm' }} Tài sản cố định</h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã TSCĐ <span class="text-red-500">*</span></label>
            <input v-model="form.code" type="text" required placeholder="VD: TSCĐ001"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
              :class="errors.code ? 'border-red-400' : ''" />
            <p v-if="errors.code" class="mt-1 text-xs text-red-600">{{ errors.code }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên tài sản <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="errors.name" class="mt-1 text-xs text-red-600">{{ errors.name }}</p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nhóm tài sản</label>
            <input v-model="form.category" type="text" placeholder="VD: Máy móc thiết bị"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Vị trí</label>
            <input v-model="form.location" type="text" placeholder="VD: Phòng kỹ thuật"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày mua <span class="text-red-500">*</span></label>
            <input v-model="form.acquisition_date" type="date" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nguyên giá (VND) <span class="text-red-500">*</span></label>
            <input v-model="form.acquisition_cost" type="number" min="0" step="any" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="errors.acquisition_cost" class="mt-1 text-xs text-red-600">{{ errors.acquisition_cost }}</p>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Thời gian khấu hao (tháng) <span class="text-red-500">*</span></label>
            <input v-model="form.useful_life_months" type="number" min="1" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p class="mt-1 text-xs text-gray-400">{{ yearsLabel }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Hao mòn lũy kế (VND)</label>
            <input v-model="form.accumulated_depreciation" type="number" min="0" step="any"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Phương pháp KH</label>
            <select v-model="form.depreciation_method"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="straight_line">Đường thẳng</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Trạng thái</label>
            <select v-model="form.status"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option value="active">Đang sử dụng</option>
              <option value="fully_depreciated">Đã khấu hao hết</option>
              <option value="disposed">Đã thanh lý</option>
            </select>
          </div>
        </div>

        <!-- Preview -->
        <div v-if="form.acquisition_cost && form.useful_life_months" class="bg-blue-50 rounded-lg p-4 text-sm space-y-1">
          <p class="font-medium text-blue-800">Thông tin khấu hao</p>
          <p class="text-blue-700">Tỷ lệ KH: <strong>{{ depRate }}%/năm</strong></p>
          <p class="text-blue-700">KH hàng tháng: <strong>{{ fmt(monthlyDep) }}</strong></p>
          <p class="text-blue-700">KH hàng năm: <strong>{{ fmt(annualDep) }}</strong></p>
          <p class="text-blue-700">Giá trị còn lại: <strong>{{ fmt(netBookValue) }}</strong></p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="2"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <div class="flex justify-end gap-3">
          <a :href="route('admin.fixed-assets.index')" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
            Hủy
          </a>
          <button type="submit" :disabled="submitting"
            class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
            {{ asset ? 'Cập nhật' : 'Tạo mới' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ asset: Object });

const { formatVnd: fmt } = useCurrency();
const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const submitting = ref(false);

const form = ref({
  code:                     props.asset?.code                     ?? '',
  name:                     props.asset?.name                     ?? '',
  category:                 props.asset?.category                 ?? '',
  acquisition_date:         props.asset?.acquisition_date         ?? '',
  acquisition_cost:         props.asset?.acquisition_cost         ?? 0,
  useful_life_months:       props.asset?.useful_life_months       ?? 60,
  depreciation_method:      props.asset?.depreciation_method      ?? 'straight_line',
  accumulated_depreciation: props.asset?.accumulated_depreciation ?? 0,
  location:                 props.asset?.location                 ?? '',
  status:                   props.asset?.status                   ?? 'active',
  notes:                    props.asset?.notes                    ?? '',
});

const yearsLabel = computed(() => {
  const m = Number(form.value.useful_life_months);
  if (!m) return '';
  const y = Math.floor(m / 12);
  const rem = m % 12;
  return y > 0 ? `${y} năm${rem > 0 ? ' ' + rem + ' tháng' : ''}` : `${m} tháng`;
});

const depRate     = computed(() => form.value.useful_life_months > 0 ? (12 / form.value.useful_life_months * 100).toFixed(2) : 0);
const monthlyDep  = computed(() => form.value.useful_life_months > 0 ? form.value.acquisition_cost / form.value.useful_life_months : 0);
const annualDep   = computed(() => monthlyDep.value * 12);
const netBookValue = computed(() => Math.max(0, form.value.acquisition_cost - form.value.accumulated_depreciation));

function submit() {
  submitting.value = true;
  if (props.asset) {
    router.put(route('admin.fixed-assets.update', props.asset.id), form.value, {
      onFinish: () => { submitting.value = false; },
    });
  } else {
    router.post(route('admin.fixed-assets.store'), form.value, {
      onFinish: () => { submitting.value = false; },
    });
  }
}
</script>
