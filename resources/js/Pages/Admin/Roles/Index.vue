<template>
  <AppLayout>
    <div class="space-y-5">
      <h1 class="text-2xl font-bold text-gray-900">Phân quyền theo nhóm</h1>

      <div class="flex gap-5 items-start">
        <!-- Role list (left) -->
        <div class="w-44 flex-shrink-0 space-y-1.5">
          <button
            v-for="role in roles"
            :key="role.name"
            @click="selectRole(role)"
            :class="[
              'w-full text-left px-4 py-3 rounded-xl text-sm transition-colors',
              selectedRole?.name === role.name
                ? 'bg-primary-600 text-white shadow-sm'
                : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-50'
            ]"
          >
            <p class="font-semibold">{{ roleMeta(role.name).label }}</p>
            <p :class="['text-xs mt-0.5 truncate', selectedRole?.name === role.name ? 'text-primary-200' : 'text-gray-400']">
              {{ roleMeta(role.name).desc }}
            </p>
          </button>
        </div>

        <!-- Permission panel (right) -->
        <div class="flex-1 bg-white rounded-xl border border-gray-200 min-h-64">
          <template v-if="selectedRole">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
              <div>
                <h2 class="text-base font-semibold text-gray-800">{{ roleMeta(selectedRole.name).label }}</h2>
                <p class="text-xs text-gray-500 mt-0.5">{{ roleMeta(selectedRole.name).desc }}</p>
              </div>
              <button
                v-if="selectedRole.name !== 'admin'"
                @click="save"
                :disabled="saving"
                class="px-5 py-2 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm font-medium rounded-lg"
              >
                {{ saving ? 'Đang lưu...' : 'Lưu thay đổi' }}
              </button>
            </div>

            <!-- Admin: read-only -->
            <div v-if="selectedRole.name === 'admin'" class="px-6 py-10 text-center text-gray-500 text-sm">
              <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
              Nhóm <strong>Admin</strong> luôn có toàn quyền hệ thống và không thể chỉnh sửa.
            </div>

            <!-- Permission checkboxes -->
            <div v-else class="px-6 py-5 space-y-6">
              <div v-for="(perms, group) in permissionGroups" :key="group">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2.5">{{ group }}</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                  <label
                    v-for="perm in perms"
                    :key="perm"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer select-none transition-colors"
                    :class="checkedPerms.includes(perm)
                      ? 'bg-primary-50 border-primary-200'
                      : 'border-gray-100 hover:bg-gray-50'"
                  >
                    <input
                      type="checkbox"
                      :value="perm"
                      v-model="checkedPerms"
                      class="w-4 h-4 text-primary-600 rounded border-gray-300"
                    >
                    <span class="text-sm text-gray-700">{{ actionLabel(perm) }}</span>
                  </label>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  roles: Array,
  permissionGroups: Object,
  selected: String,
});

const ROLE_META = {
  admin:      { label: 'Admin',       desc: 'Toàn quyền hệ thống' },
  director:   { label: 'Giám đốc',    desc: 'Xem và duyệt tất cả' },
  sales:      { label: 'Kinh doanh',  desc: 'Báo giá, đơn hàng, hoa hồng' },
  warehouse:  { label: 'Thủ kho',     desc: 'Sản phẩm, nhập/xuất kho' },
  technical:  { label: 'Kỹ thuật',   desc: 'Dự án, ticket, bảo hành' },
  accounting: { label: 'Kế toán',    desc: 'Hóa đơn, kế toán' },
  cskh:       { label: 'CSKH',       desc: 'Chăm sóc khách hàng' },
};

const ACTION_LABELS = {
  view: 'Xem', create: 'Tạo mới', edit: 'Sửa', delete: 'Xóa',
  manage: 'Quản lý', approve: 'Duyệt', approve_l1: 'Duyệt cấp 1',
  assign: 'Phân công', close: 'Đóng', pay: 'Thanh toán',
  users: 'Quản lý users', roles: 'Quản lý quyền',
};

const roleMeta = (name) => ROLE_META[name] ?? { label: name, desc: '' };

const actionLabel = (perm) => {
  const action = perm.split('.').slice(1).join('.');
  return ACTION_LABELS[action] ?? action;
};

const initialRole = props.roles.find(r => r.name === props.selected)
  ?? props.roles.find(r => r.name !== 'admin')
  ?? props.roles[0];

const selectedRole = ref(initialRole);
const checkedPerms = ref([...initialRole.permissions]);

function selectRole(role) {
  selectedRole.value = role;
  checkedPerms.value = [...role.permissions];
}

const saving = ref(false);

function save() {
  saving.value = true;
  router.put(
    route('admin.roles.update', selectedRole.value.id),
    { permissions: checkedPerms.value },
    {
      preserveScroll: true,
      onFinish: () => { saving.value = false; },
    }
  );
}
</script>
