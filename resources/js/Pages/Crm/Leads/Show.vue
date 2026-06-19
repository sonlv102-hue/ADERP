<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('crm.leads.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ lead.full_name }}</h1>
          <StatusBadge :color="lead.status_color">{{ lead.status_label }}</StatusBadge>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <Link v-if="can('leads.edit')" :href="route('crm.leads.edit', lead.id)"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <button
            v-if="can('leads.create') && lead.status !== 'won' && lead.status !== 'lost'"
            @click="showConvertModal = true"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Chuyển thành KH
          </button>
          <button
            v-if="can('leads.delete') && (lead.status === 'new' || lead.status === 'lost')"
            @click="confirmDelete = true"
            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Xóa
          </button>
        </div>
      </div>

      <!-- Info card -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Thông tin Lead</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-8 text-sm">
          <div>
            <span class="text-gray-500">Mã Lead</span>
            <p class="font-medium text-gray-900 mt-0.5 font-mono">{{ lead.code }}</p>
          </div>
          <div>
            <span class="text-gray-500">Công ty</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ lead.company_name ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Số điện thoại</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ lead.phone ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Email</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ lead.email ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Nguồn</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ sourceLabel(lead.source) }}</p>
          </div>
          <div>
            <span class="text-gray-500">Phụ trách</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ lead.assigned_to_name ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Ngày follow-up</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ lead.next_follow_up ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Giá trị dự kiến</span>
            <p class="font-medium text-gray-900 mt-0.5">
              {{ lead.expected_value ? Number(lead.expected_value).toLocaleString('vi-VN') + ' ₫' : '—' }}
            </p>
          </div>
          <div>
            <span class="text-gray-500">Người tạo</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ lead.creator_name ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Ngày tạo</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ lead.created_at }}</p>
          </div>
          <div v-if="lead.converted_customer_id" class="sm:col-span-2">
            <span class="text-gray-500">Khách hàng đã chuyển đổi</span>
            <p class="font-medium text-gray-900 mt-0.5">
              <Link :href="route('crm.customers.show', lead.converted_customer_id)"
                class="text-primary-600 hover:text-primary-800">
                {{ lead.converted_customer_code }}
              </Link>
            </p>
          </div>
          <div class="sm:col-span-2">
            <span class="text-gray-500">Ghi chú</span>
            <p class="font-medium text-gray-900 mt-0.5 whitespace-pre-line">{{ lead.notes ?? '—' }}</p>
          </div>
        </div>
      </div>

      <!-- Status timeline -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Quy trình</h2>
        <div class="flex items-center gap-0 flex-wrap">
          <template v-for="(step, i) in pipeline" :key="step.value">
            <div class="flex items-center">
              <div :class="[
                'px-3 py-1.5 rounded-full text-xs font-semibold',
                lead.status === step.value
                  ? 'bg-primary-600 text-white'
                  : isPassed(step.value) ? 'bg-gray-200 text-gray-700' : 'bg-gray-100 text-gray-400'
              ]">{{ step.label }}</div>
              <div v-if="i < pipeline.length - 1" class="w-6 h-0.5 bg-gray-200 mx-1" />
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Convert modal -->
    <Modal :show="showConvertModal" @close="showConvertModal = false" max-width="md">
      <template #title>Chuyển đổi thành Khách hàng</template>
      <div class="space-y-4 text-sm text-gray-600">
        <p>Thông tin dưới đây sẽ được dùng để tạo khách hàng mới. Có thể chỉnh sửa trước khi xác nhận.</p>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tên khách hàng</label>
          <input v-model="convertForm.name" type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Công ty</label>
          <input v-model="convertForm.company" type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
          <input v-model="convertForm.phone" type="tel"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input v-model="convertForm.email" type="email"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
        </div>
      </div>
      <template #footer>
        <button @click="showConvertModal = false"
          class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doConvert" :disabled="convertForm.processing"
          class="bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white px-4 py-2 rounded-lg text-sm font-medium">
          {{ convertForm.processing ? 'Đang xử lý...' : 'Xác nhận chuyển đổi' }}
        </button>
      </template>
    </Modal>

    <!-- Delete modal -->
    <Modal :show="confirmDelete" @close="confirmDelete = false">
      <template #title>Xác nhận xóa</template>
      <p class="text-gray-600">Bạn có chắc muốn xóa lead <strong>{{ lead.full_name }}</strong> không?</p>
      <template #footer>
        <button @click="confirmDelete = false"
          class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doDelete"
          class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">Xóa</button>
      </template>
    </Modal>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Modal from '@/Components/Shared/Modal.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ lead: Object });

const { hasPermission } = usePermission();
const can = hasPermission;

const showConvertModal = ref(false);
const confirmDelete = ref(false);

const pipeline = [
  { value: 'new', label: 'Mới' },
  { value: 'contacted', label: 'Đã liên hệ' },
  { value: 'qualified', label: 'Tiềm năng' },
  { value: 'proposal', label: 'Báo giá' },
  { value: 'negotiation', label: 'Đàm phán' },
  { value: 'won', label: 'Chốt được' },
];

const pipelineOrder = pipeline.map(s => s.value);
const isPassed = (value) => {
  const currentIdx = pipelineOrder.indexOf(props.lead.status);
  const valueIdx = pipelineOrder.indexOf(value);
  return valueIdx < currentIdx;
};

const sourceLabels = {
  website: 'Website', referral: 'Giới thiệu', 'cold-call': 'Cold Call',
  event: 'Sự kiện', other: 'Khác',
};
const sourceLabel = (s) => sourceLabels[s] ?? s ?? '—';

const convertForm = useForm({
  name:    props.lead.full_name,
  company: props.lead.company_name ?? '',
  phone:   props.lead.phone ?? '',
  email:   props.lead.email ?? '',
});

const doConvert = () => {
  convertForm.post(route('crm.leads.convert', props.lead.id), {
    onSuccess: () => { showConvertModal.value = false; },
  });
};

const doDelete = () => {
  router.delete(route('crm.leads.destroy', props.lead.id), {
    onSuccess: () => { confirmDelete.value = false; },
  });
};
</script>
