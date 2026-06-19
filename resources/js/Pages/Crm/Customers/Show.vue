<template>
  <AppLayout>
    <div class="space-y-6">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('crm.customers.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <h1 class="text-2xl font-bold text-gray-900">{{ customer.name }}</h1>
        </div>
        <Link :href="route('crm.customers.edit', customer.id)"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          Sửa
        </Link>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-800 mb-4">Thông tin khách hàng</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-4 gap-x-8 text-sm">
          <div>
            <span class="text-gray-500">Mã khách hàng</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ customer.code }}</p>
          </div>
          <div>
            <span class="text-gray-500">Công ty</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ customer.company ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Mã số thuế</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ customer.tax_code ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Số điện thoại</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ customer.phone ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Email</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ customer.email ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Địa chỉ</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ customer.address ?? '—' }}</p>
          </div>
          <div>
            <span class="text-gray-500">Trạng thái</span>
            <div class="mt-0.5">
              <StatusBadge :color="customer.lead_status_color">{{ customer.lead_status_label }}</StatusBadge>
            </div>
          </div>
          <div>
            <span class="text-gray-500">Phụ trách</span>
            <p class="font-medium text-gray-900 mt-0.5">{{ customer.assigned_user?.name ?? '—' }}</p>
          </div>
          <div class="sm:col-span-2">
            <span class="text-gray-500">Ghi chú</span>
            <p class="font-medium text-gray-900 mt-0.5 whitespace-pre-line">{{ customer.notes ?? '—' }}</p>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
          <h2 class="text-base font-semibold text-gray-800">Người liên hệ</h2>
          <button @click="showContactModal = true"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Thêm liên hệ
          </button>
        </div>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Họ tên</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Chức vụ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Điện thoại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Email</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Chính</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="contact in customer.contacts" :key="contact.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-medium text-gray-900">{{ contact.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ contact.title ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ contact.phone ?? '—' }}</td>
              <td class="px-5 py-3 text-gray-600">{{ contact.email ?? '—' }}</td>
              <td class="px-5 py-3">
                <StatusBadge v-if="contact.is_primary" color="green">Chính</StatusBadge>
                <StatusBadge v-else color="gray">—</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <button @click="confirmDeleteContact(contact)"
                  class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!customer.contacts?.length">
              <td colspan="6" class="px-5 py-10 text-center text-gray-400">Chưa có người liên hệ nào</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <Modal :show="showContactModal" @close="closeContactModal" max-width="md">
      <template #title>Thêm người liên hệ</template>
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Họ tên <span class="text-red-500">*</span></label>
          <input v-model="contactForm.name" type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': contactForm.errors.name }" />
          <p v-if="contactForm.errors.name" class="mt-1 text-xs text-red-600">{{ contactForm.errors.name }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Chức vụ</label>
          <input v-model="contactForm.title" type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': contactForm.errors.title }" />
          <p v-if="contactForm.errors.title" class="mt-1 text-xs text-red-600">{{ contactForm.errors.title }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
          <input v-model="contactForm.phone" type="tel"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': contactForm.errors.phone }" />
          <p v-if="contactForm.errors.phone" class="mt-1 text-xs text-red-600">{{ contactForm.errors.phone }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input v-model="contactForm.email" type="email"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
            :class="{ 'border-red-500': contactForm.errors.email }" />
          <p v-if="contactForm.errors.email" class="mt-1 text-xs text-red-600">{{ contactForm.errors.email }}</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <input v-model="contactForm.is_primary" id="is_primary" type="checkbox"
            class="h-4 w-4 text-primary-600 rounded border-gray-300" />
          <label for="is_primary" class="text-sm text-gray-700">Người liên hệ chính</label>
        </div>
      </div>
      <template #footer>
        <button @click="closeContactModal"
          class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="submitContact" :disabled="contactForm.processing"
          class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-4 py-2 rounded-lg text-sm font-medium">
          {{ contactForm.processing ? 'Đang lưu...' : 'Thêm' }}
        </button>
      </template>
    </Modal>

    <Modal :show="deleteContactTarget !== null" @close="deleteContactTarget = null">
      <template #title>Xác nhận xóa</template>
      <p class="text-gray-600">Bạn có chắc muốn xóa người liên hệ <strong>{{ deleteContactTarget?.name }}</strong> không?</p>
      <template #footer>
        <button @click="deleteContactTarget = null"
          class="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50">Hủy</button>
        <button @click="doDeleteContact"
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

const props = defineProps({ customer: Object });

const showContactModal = ref(false);
const deleteContactTarget = ref(null);

const contactForm = useForm({
  name: '',
  title: '',
  phone: '',
  email: '',
  is_primary: false,
});

const closeContactModal = () => {
  showContactModal.value = false;
  contactForm.reset();
};

const submitContact = () => {
  contactForm.post(route('crm.customers.contacts.store', props.customer.id), {
    onSuccess: () => { closeContactModal(); },
  });
};

const confirmDeleteContact = (contact) => { deleteContactTarget.value = contact; };

const doDeleteContact = () => {
  router.delete(route('crm.customers.contacts.destroy', [props.customer.id, deleteContactTarget.value.id]), {
    onSuccess: () => { deleteContactTarget.value = null; },
  });
};
</script>
