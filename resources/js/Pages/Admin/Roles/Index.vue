<template>
  <AppLayout>
    <div class="space-y-6 max-w-[1600px] mx-auto">
      <!-- Title -->
      <div class="flex justify-between items-center">
        <div>
          <h1 class="text-2xl font-bold text-slate-900">Quản trị Phân quyền</h1>
          <p class="text-sm text-slate-500 mt-1">Quản lý các nhóm vai trò và thiết lập ma trận phân quyền hệ thống.</p>
        </div>
        <button
          @click="openAddRoleModal"
          class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-xl shadow-sm hover:shadow transition-all duration-200 flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Thêm vai trò mới
        </button>
      </div>

      <div class="flex gap-6 items-start flex-col lg:flex-row">
        <!-- Role list (left) -->
        <div class="w-full lg:w-72 flex-shrink-0 space-y-3">
          <div class="bg-white rounded-2xl border border-slate-200 p-4 space-y-2.5 shadow-sm">
            <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider px-2">Danh sách nhóm</h3>
            <div class="space-y-1.5">
              <div
                v-for="role in roles"
                :key="role.code"
                @click="selectRole(role)"
                :class="[
                  'group relative w-full text-left px-4 py-3.5 rounded-xl cursor-pointer transition-all duration-200 border',
                  selectedRole?.code === role.code
                    ? 'bg-slate-900 border-slate-900 text-white shadow-md'
                    : 'bg-white border-slate-100 text-slate-700 hover:bg-slate-50 hover:border-slate-200'
                ]"
              >
                <div class="flex justify-between items-start pr-6">
                  <div>
                    <span class="font-semibold text-sm flex items-center gap-1.5">
                      {{ role.name }}
                      <span v-if="role.is_system" class="px-1.5 py-0.5 text-[10px] font-medium bg-slate-100 text-slate-600 rounded">Hệ thống</span>
                    </span>
                    <p :class="['text-xs mt-1 line-clamp-1', selectedRole?.code === role.code ? 'text-slate-300' : 'text-slate-400']">
                      {{ role.description || 'Chưa có mô tả' }}
                    </p>
                  </div>
                </div>

                <div class="mt-2.5 flex items-center justify-between">
                  <span :class="['text-[11px] font-medium px-2 py-0.5 rounded-full', selectedRole?.code === role.code ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600']">
                    {{ role.users_count }} người dùng
                  </span>
                  
                  <!-- Delete button (only for non-system roles) -->
                  <button
                    v-if="!role.is_system"
                    @click.stop="confirmDeleteRole(role)"
                    class="opacity-0 group-hover:opacity-100 p-1 hover:bg-red-50 hover:text-red-600 rounded-lg text-slate-400 transition-all duration-200 absolute top-3.5 right-3"
                    title="Xóa vai trò"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Permission Matrix (right) -->
        <div class="flex-1 w-full bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
          <template v-if="selectedRole">
            <!-- Header bar -->
            <div class="px-6 py-5 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-slate-50/50">
              <div>
                <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                  {{ selectedRole.name }}
                  <span class="text-xs font-normal text-slate-500">({{ selectedRole.code }})</span>
                </h2>
                <p class="text-xs text-slate-500 mt-1">{{ selectedRole.description }}</p>
              </div>
              <button
                v-if="!['admin', 'super_admin'].includes(selectedRole.code)"
                @click="save"
                :disabled="saving"
                class="px-5 py-2 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm font-semibold rounded-xl shadow-sm hover:shadow transition-all duration-200"
              >
                {{ saving ? 'Đang lưu...' : 'Lưu ma trận quyền' }}
              </button>
            </div>

            <!-- Super Admin View (Read Only) -->
            <div v-if="['admin', 'super_admin'].includes(selectedRole.code)" class="p-12 text-center text-slate-500">
              <div class="w-16 h-16 bg-slate-100 text-slate-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
              </div>
              <p class="font-bold text-slate-800 text-base">Nhóm đặc quyền hệ thống</p>
              <p class="text-sm text-slate-500 mt-1.5 max-w-md mx-auto">
                Nhóm <strong>{{ selectedRole.name }}</strong> luôn có toàn bộ quyền hạn trong hệ thống để bảo trì và quản trị. Không thể chỉnh sửa ma trận của nhóm này.
              </p>
            </div>

            <!-- Matrix checkboxes -->
            <div v-else class="p-6 space-y-6">
              <!-- Matrix Table -->
              <div class="overflow-x-auto rounded-xl border border-slate-100 shadow-inner">
                <table class="w-full text-left border-collapse">
                  <thead>
                    <tr class="bg-slate-50 text-slate-600 uppercase text-[10px] font-bold tracking-wider border-b border-slate-100">
                      <th class="py-3.5 px-4 font-semibold text-slate-700 min-w-[200px]">Phân hệ / Module</th>
                      <th v-for="col in MATRIX_COLUMNS" :key="col.action" class="py-3.5 px-2 text-center font-semibold text-slate-700">
                        {{ col.label }}
                      </th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 text-sm">
                    <tr v-for="rowKey in MATRIX_ROWS" :key="rowKey" class="hover:bg-slate-50/50 transition-colors">
                      <td class="py-3 px-4 font-medium text-slate-700">
                        {{ rowLabel(rowKey) }}
                        <span class="block text-[10px] text-slate-400 font-mono font-normal mt-0.5">{{ rowKey }}</span>
                      </td>
                      <td v-for="col in MATRIX_COLUMNS" :key="col.action" class="py-3 px-2 text-center">
                        <!-- Find if permission exists -->
                        <template v-if="getPermCode(rowKey, col.action)">
                          <label class="inline-flex items-center justify-center p-1.5 cursor-pointer rounded-lg hover:bg-slate-100 transition-colors">
                            <input
                              type="checkbox"
                              :value="getPermCode(rowKey, col.action)"
                              v-model="checkedPerms"
                              class="w-4 h-4 text-primary-600 rounded border-slate-300 focus:ring-primary-500 cursor-pointer"
                            />
                          </label>
                        </template>
                        <template v-else>
                          <span class="text-slate-200 select-none">-</span>
                        </template>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Collapsible Compatibility Legacy Section -->
              <div class="border border-slate-100 rounded-xl overflow-hidden">
                <button
                  type="button"
                  @click="showLegacy = !showLegacy"
                  class="w-full px-5 py-3.5 bg-slate-50 hover:bg-slate-100/70 border-b border-slate-100 flex items-center justify-between transition-colors text-left"
                >
                  <div>
                    <h3 class="text-sm font-semibold text-slate-700 flex items-center gap-2">
                      <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                      </svg>
                      Quyền tương thích hệ thống cũ
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Các quyền cũ phục vụ bảo mật cho những chức năng chưa nâng cấp lên phân quyền động.</p>
                  </div>
                  <svg :class="['w-5 h-5 text-slate-400 transition-transform duration-200', showLegacy ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>

                <div v-show="showLegacy" class="p-5 bg-white grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                  <label
                    v-for="perm in legacyPermissions"
                    :key="perm.code"
                    class="flex items-center gap-2 px-3 py-2 rounded-lg border cursor-pointer select-none transition-colors"
                    :class="checkedPerms.includes(perm.code) ? 'bg-primary-50/50 border-primary-200' : 'border-slate-100 hover:bg-slate-50'"
                  >
                    <input
                      type="checkbox"
                      :value="perm.code"
                      v-model="checkedPerms"
                      class="w-4 h-4 text-primary-600 rounded border-slate-300 focus:ring-primary-500"
                    />
                    <div class="min-w-0 flex-1">
                      <p class="text-xs font-medium text-slate-700 truncate" :title="perm.code">{{ perm.code }}</p>
                      <p class="text-[10px] text-slate-400 truncate">{{ perm.name }}</p>
                    </div>
                  </label>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Add Role Modal -->
    <div v-if="addRoleModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
      <div class="bg-white rounded-2xl w-full max-w-md border border-slate-200 shadow-xl overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
          <h3 class="font-bold text-slate-900">Thêm vai trò mới</h3>
          <button @click="addRoleModalOpen = false" class="text-slate-400 hover:text-slate-600 transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <form @submit.prevent="submitNewRole" class="p-6 space-y-4">
          <div>
            <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">Tên vai trò <span class="text-red-500">*</span></label>
            <input
              v-model="newRoleForm.name"
              type="text"
              required
              placeholder="VD: Kế toán viên"
              class="w-full px-3.5 py-2 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all text-sm"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">Mã vai trò (code) <span class="text-red-500">*</span></label>
            <input
              v-model="newRoleForm.code"
              type="text"
              required
              placeholder="VD: accountant"
              class="w-full px-3.5 py-2 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all text-sm font-mono"
            />
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 uppercase mb-1">Mô tả ngắn</label>
            <textarea
              v-model="newRoleForm.description"
              rows="3"
              placeholder="Nhập mô tả nhiệm vụ của vai trò..."
              class="w-full px-3.5 py-2 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all text-sm resize-none"
            ></textarea>
          </div>
          <div class="flex justify-end gap-3 pt-2">
            <button
              type="button"
              @click="addRoleModalOpen = false"
              class="px-4 py-2 border border-slate-200 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-xl transition-colors"
            >
              Hủy
            </button>
            <button
              type="submit"
              :disabled="submittingRole"
              class="px-5 py-2 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors"
            >
              Tạo vai trò
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  roles: Array,
  allPermissions: Array,
  selected: String,
});

const showLegacy = ref(false);
const addRoleModalOpen = ref(false);
const submittingRole = ref(false);

const newRoleForm = ref({
  name: '',
  code: '',
  description: '',
});

// Configure rows & columns for Matrix
const MATRIX_ROWS = [
  'dashboard',
  'sales.orders',
  'sales.invoices',
  'purchases.orders',
  'purchases.invoices',
  'warehouse.stock_entries',
  'warehouse.stock_exits',
  'projects',
  'projects.costs',
  'accounting.journals',
  'hr.employees',
  'reports.inventory',
  'reports.financial',
  'reports.cashflow',
];

const MATRIX_COLUMNS = [
  { action: 'view',    label: 'Xem' },
  { action: 'create',  label: 'Thêm' },
  { action: 'update',  label: 'Sửa' },
  { action: 'delete',  label: 'Xóa' },
  { action: 'approve',  label: 'Duyệt' },
  { action: 'cancel',   label: 'Hủy' },
  { action: 'post',     label: 'Ghi sổ' },
  { action: 'reverse',  label: 'Đảo' },
  { action: 'export',   label: 'Xuất' },
  { action: 'import',   label: 'Import' },
];

const ROW_LABELS = {
  'dashboard': 'Bảng điều khiển (Dashboard)',
  'sales.orders': 'Đơn hàng bán',
  'sales.invoices': 'Quản lý hóa đơn bán',
  'purchases.orders': 'Đơn mua hàng',
  'purchases.invoices': 'Hóa đơn đầu vào',
  'warehouse.stock_entries': 'Nhập kho (Stock Entries)',
  'warehouse.stock_exits': 'Xuất kho (Stock Exits)',
  'projects': 'Dự án thi công',
  'projects.costs': 'Chi phí dự án',
  'accounting.journals': 'Phiếu kế toán tổng hợp',
  'hr.employees': 'Hồ sơ nhân sự (CBCNV)',
  'reports.inventory': 'Báo cáo tồn kho',
  'reports.financial': 'Báo cáo tài chính',
  'reports.cashflow': 'Báo cáo dòng tiền',
};

function rowLabel(key) {
  return ROW_LABELS[key] ?? key;
}

// Split permissions into structured matrix items and legacy items
const structuredPermissions = computed(() => {
  return props.allPermissions.filter(p => p.module !== 'old_compat');
});

const legacyPermissions = computed(() => {
  return props.allPermissions.filter(p => p.module === 'old_compat');
});

// Helper: get permission code for key + action
function getPermCode(rowKey, colAction) {
  // Map column actions to specific permission actions if needed
  let actionMatch = colAction;
  if (colAction === 'approve') {
    // Also matches confirm/complete
    const match = structuredPermissions.value.find(
      p => p.menu_key === rowKey && ['approve', 'confirm', 'complete'].includes(p.action)
    );
    return match ? match.code : null;
  }

  const found = structuredPermissions.value.find(
    p => p.menu_key === rowKey && p.action === actionMatch
  );
  return found ? found.code : null;
}

// Role selection management
const initialRole = props.roles.find(r => r.code === props.selected)
  || props.roles.find(r => !['admin', 'super_admin'].includes(r.code))
  || props.roles[0];

const selectedRole = ref(initialRole);
const checkedPerms = ref([...(initialRole?.permissions || [])]);

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

// Add role logic
function openAddRoleModal() {
  newRoleForm.value = { name: '', code: '', description: '' };
  addRoleModalOpen.value = true;
}

function submitNewRole() {
  submittingRole.value = true;
  router.post(
    route('admin.roles.store'),
    newRoleForm.value,
    {
      onSuccess: () => {
        addRoleModalOpen.value = false;
      },
      onFinish: () => {
        submittingRole.value = false;
      }
    }
  );
}

// Delete role logic
function confirmDeleteRole(role) {
  if (confirm(`Bạn có chắc chắn muốn xóa nhóm vai trò "${role.name}" không?`)) {
    router.delete(route('admin.roles.destroy', role.id), {
      onSuccess: () => {
        if (selectedRole.value.id === role.id) {
          selectRole(props.roles.find(r => r.code === 'admin') || props.roles[0]);
        }
      }
    });
  }
}
</script>
