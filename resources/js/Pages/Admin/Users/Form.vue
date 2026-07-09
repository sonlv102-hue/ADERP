<template>
  <AppLayout>
    <div class="max-w-6xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('admin.users.index')" class="p-2 bg-white border border-slate-200 text-slate-500 hover:text-slate-700 hover:border-slate-300 rounded-xl transition-all shadow-sm">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <h1 class="text-2xl font-bold text-slate-900">{{ user ? 'Sửa tài khoản' : 'Tạo tài khoản mới' }}</h1>
          <p class="text-sm text-slate-500 mt-0.5">{{ user ? 'Cấu hình thông tin cá nhân và thiết lập phân quyền cá nhân.' : 'Tạo tài khoản và gán quyền ban đầu.' }}</p>
        </div>
      </div>

      <!-- Tab header -->
      <div class="flex border-b border-slate-200 gap-6">
        <button
          type="button"
          @click="activeTab = 'info'"
          :class="[
            'pb-3 font-semibold text-sm border-b-2 transition-all px-2',
            activeTab === 'info'
              ? 'border-primary-600 text-primary-600'
              : 'border-transparent text-slate-500 hover:text-slate-800'
          ]"
        >
          Thông tin cơ bản
        </button>
        <button
          v-if="user"
          type="button"
          @click="activeTab = 'permissions'"
          :class="[
            'pb-3 font-semibold text-sm border-b-2 transition-all px-2',
            activeTab === 'permissions'
              ? 'border-primary-600 text-primary-600'
              : 'border-transparent text-slate-500 hover:text-slate-800'
          ]"
        >
          Phân quyền người dùng
        </button>
      </div>

      <form @submit.prevent="submit" class="space-y-6">
        <!-- TAB 1: INFO -->
        <div v-show="activeTab === 'info'" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-6 shadow-sm">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Họ tên <span class="text-red-500">*</span></label>
              <input v-model="form.name" type="text" required class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                :class="{ 'border-red-500': form.errors.name }" />
              <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Email <span class="text-red-500">*</span></label>
              <input v-model="form.email" type="email" required class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                :class="{ 'border-red-500': form.errors.email }" />
              <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Mật khẩu {{ user ? '(để trống = không đổi)' : '*' }}</label>
              <input v-model="form.password" type="password" :required="!user" class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none text-sm"
                :class="{ 'border-red-500': form.errors.password }" />
              <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Xác nhận mật khẩu</label>
              <input v-model="form.password_confirmation" type="password" :required="!user" class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none text-sm" />
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Số điện thoại</label>
              <input v-model="form.phone" type="tel" class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none text-sm" />
            </div>

            <!-- Role select ONLY for user creation (updates handled in permissions tab) -->
            <div v-if="!user">
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Vai trò <span class="text-red-500">*</span></label>
              <select v-model="form.role_ids[0]" required class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none text-sm">
                <option value="">-- Chọn vai trò --</option>
                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
              </select>
              <p v-if="form.errors.role_ids" class="mt-1 text-xs text-red-600">{{ form.errors.role_ids }}</p>
            </div>
          </div>

          <div class="flex items-center gap-2">
            <input v-model="form.is_active" id="is_active" type="checkbox" class="h-4.5 w-4.5 text-primary-600 rounded border-slate-300 focus:ring-primary-500 cursor-pointer" />
            <label for="is_active" class="text-sm font-semibold text-slate-700 cursor-pointer">Tài khoản đang hoạt động</label>
          </div>

          <!-- Salary & Tax fields -->
          <div class="border-t border-slate-100 pt-6">
            <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4">Lương & Thuế</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
              <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Lương cơ bản (₫)</label>
                <input v-model.number="form.base_salary" type="number" min="0" step="any"
                  class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none font-mono text-right text-sm" />
              </div>
              <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Phụ cấp (₫)</label>
                <input v-model.number="form.allowance" type="number" min="0" step="any"
                  class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none font-mono text-right text-sm" />
              </div>
              <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Số người phụ thuộc</label>
                <input v-model.number="form.dependents_count" type="number" min="0" max="10"
                  class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none text-sm" />
                <p class="mt-1.5 text-xs text-slate-400">Giảm trừ: 4,400,000 ₫/người/tháng</p>
              </div>
              <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1.5">Mã số thuế cá nhân</label>
                <input v-model="form.pit_tax_code" type="text" maxlength="20" placeholder="VD: 8123456789"
                  class="w-full px-3.5 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary-500 outline-none font-mono text-sm" />
              </div>
            </div>
          </div>
        </div>

        <!-- TAB 2: ROLES & OVERRIDES (EDIT ONLY) -->
        <div v-if="user" v-show="activeTab === 'permissions'" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          
          <!-- Roles & Computed panel (left 1/3) -->
          <div class="space-y-6">
            <!-- Roles Checklist -->
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
              <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Danh sách vai trò</h3>
              <div class="divide-y divide-slate-100 max-h-60 overflow-y-auto pr-1">
                <label
                  v-for="role in roles"
                  :key="role.id"
                  class="flex items-start gap-3 py-3 cursor-pointer select-none"
                >
                  <input
                    type="checkbox"
                    :value="role.id"
                    v-model="form.role_ids"
                    class="w-4.5 h-4.5 text-primary-600 rounded border-slate-300 focus:ring-primary-500 mt-0.5 cursor-pointer"
                  />
                  <div>
                    <span class="text-sm font-semibold text-slate-700">{{ role.name }}</span>
                    <p class="text-xs text-slate-400 mt-0.5">{{ role.description || 'Chưa có mô tả' }}</p>
                  </div>
                </label>
              </div>
            </div>

            <!-- Computed Permissions display -->
            <div class="bg-slate-900 text-white rounded-2xl p-5 shadow-md space-y-4">
              <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-slate-300">Quyền hạn thực tế</h3>
                <p class="text-[11px] text-slate-400 mt-0.5">Quyền thực thi của người dùng sau khi áp dụng gán đè.</p>
              </div>
              
              <div v-if="isUserSuperAdmin" class="p-3 bg-slate-800 border border-slate-700 rounded-xl text-center">
                <span class="text-xs font-bold text-primary-400 uppercase tracking-wide">Quyền hạn tối cao (Super Admin)</span>
                <p class="text-[10px] text-slate-400 mt-1">Người dùng được cấp tất cả các quyền hệ thống.</p>
              </div>
              
              <div class="space-y-3 max-h-96 overflow-y-auto pr-1 text-xs">
                <div v-for="(group, key) in computedGroupedPermissions" :key="key" class="space-y-1">
                  <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">{{ key }}</span>
                  <div class="flex flex-wrap gap-1">
                    <span
                      v-for="perm in group"
                      :key="perm.code"
                      class="px-2 py-0.5 bg-slate-800 border border-slate-700 text-slate-200 rounded text-[10px]"
                    >
                      {{ perm.action }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Overrides panel (right 2/3) -->
          <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
            <div class="px-6 py-4.5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
              <div>
                <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Thiết lập quyền gán đè</h3>
                <p class="text-xs text-slate-500 mt-0.5">Bắt buộc Cho phép (Allow) hoặc Chặn (Deny) quyền cụ thể bất chấp vai trò.</p>
              </div>
              <input
                v-model="searchTerm"
                type="text"
                placeholder="Tìm kiếm quyền..."
                class="px-3 py-1.5 border border-slate-200 rounded-xl outline-none focus:ring-2 focus:ring-primary-500 text-xs w-48"
              />
            </div>

            <!-- Overrides Grid -->
            <div class="divide-y divide-slate-100 overflow-y-auto max-h-[640px]">
              <div v-for="moduleKey in filteredModules" :key="moduleKey" class="p-5 space-y-3">
                <h4 class="text-xs font-bold text-slate-400 uppercase tracking-wider">{{ moduleKey }}</h4>
                
                <div class="space-y-2">
                  <div
                    v-for="perm in filteredPermissionsByModule(moduleKey)"
                    :key="perm.id"
                    class="flex items-center justify-between p-3 rounded-xl border border-slate-50 hover:bg-slate-50/60 hover:border-slate-100 transition-all text-xs"
                  >
                    <div class="min-w-0 flex-1 pr-4">
                      <p class="font-semibold text-slate-700 flex items-center gap-1.5">
                        {{ perm.name }}
                        <span class="text-[9px] font-mono text-slate-400">({{ perm.code }})</span>
                      </p>
                      <p class="text-[10px] text-slate-400 mt-0.5 truncate">{{ perm.description || 'Không có mô tả' }}</p>
                    </div>

                    <div class="flex items-center gap-1.5 flex-shrink-0">
                      <!-- Radio Options -->
                      <label
                        :class="[
                          'px-2.5 py-1.5 border rounded-lg cursor-pointer font-medium text-[10px] transition-all',
                          getOverrideValue(perm.id) === 'default'
                            ? 'bg-slate-100 border-slate-200 text-slate-700'
                            : 'border-slate-200 text-slate-400 hover:bg-slate-50'
                        ]"
                      >
                        <input
                          type="radio"
                          :name="'override_' + perm.id"
                          value="default"
                          :checked="getOverrideValue(perm.id) === 'default'"
                          @change="setOverride(perm.id, 'default')"
                          class="hidden"
                        />
                        Mặc định
                      </label>

                      <label
                        :class="[
                          'px-2.5 py-1.5 border rounded-lg cursor-pointer font-medium text-[10px] transition-all',
                          getOverrideValue(perm.id) === 'allow'
                            ? 'bg-green-50 border-green-200 text-green-700 shadow-sm'
                            : 'border-slate-200 text-slate-400 hover:bg-slate-50'
                        ]"
                      >
                        <input
                          type="radio"
                          :name="'override_' + perm.id"
                          value="allow"
                          :checked="getOverrideValue(perm.id) === 'allow'"
                          @change="setOverride(perm.id, 'allow')"
                          class="hidden"
                        />
                        Cho phép
                      </label>

                      <label
                        :class="[
                          'px-2.5 py-1.5 border rounded-lg cursor-pointer font-medium text-[10px] transition-all',
                          getOverrideValue(perm.id) === 'deny'
                            ? 'bg-red-50 border-red-200 text-red-700 shadow-sm'
                            : 'border-slate-200 text-slate-400 hover:bg-slate-50'
                        ]"
                      >
                        <input
                          type="radio"
                          :name="'override_' + perm.id"
                          value="deny"
                          :checked="getOverrideValue(perm.id) === 'deny'"
                          @change="setOverride(perm.id, 'deny')"
                          class="hidden"
                        />
                        Chặn
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Submit buttons -->
        <div class="flex gap-3 bg-slate-50 rounded-2xl p-4 border border-slate-200">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2.5 rounded-xl font-semibold text-sm shadow-sm transition-all duration-200">
            {{ form.processing ? 'Đang lưu...' : (user ? 'Lưu thay đổi' : 'Tạo tài khoản') }}
          </button>
          <Link :href="route('admin.users.index')"
            class="px-6 py-2.5 bg-white border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 hover:bg-slate-50 shadow-sm transition-all duration-200">
            Hủy
          </Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  user: { type: Object, default: null },
  roles: Array,
  allPermissions: { type: Array, default: () => [] },
  userOverrides: { type: Object, default: () => ({}) },
  computedPermissions: { type: Array, default: () => [] },
});

const activeTab = ref('info');
const searchTerm = ref('');

// Form state
const form = useForm({
  name:                  props.user?.name                 ?? '',
  email:                 props.user?.email                ?? '',
  password:              '',
  password_confirmation: '',
  phone:                 props.user?.phone                ?? '',
  role_ids:              props.user?.role_ids             ?? [],
  is_active:             props.user?.is_active            ?? true,
  base_salary:           props.user?.base_salary          ?? 0,
  allowance:             props.user?.allowance            ?? 0,
  dependents_count:      props.user?.dependents_count     ?? 0,
  pit_tax_code:          props.user?.pit_tax_code         ?? '',
  overrides:             { ...props.userOverrides }, // key is permission_id, value is 'allow'|'deny'|'default'
});

// Search & filter overrides
const modules = computed(() => {
  const list = props.allPermissions.map(p => p.module);
  return [...new Set(list)];
});

const filteredModules = computed(() => {
  if (!searchTerm.value) return modules.value;
  const matchModules = props.allPermissions
    .filter(p => p.name.toLowerCase().includes(searchTerm.value.toLowerCase()) || p.code.toLowerCase().includes(searchTerm.value.toLowerCase()))
    .map(p => p.module);
  return [...new Set(matchModules)];
});

function filteredPermissionsByModule(moduleName) {
  const modulePerms = props.allPermissions.filter(p => p.module === moduleName);
  if (!searchTerm.value) return modulePerms;
  return modulePerms.filter(p =>
    p.name.toLowerCase().includes(searchTerm.value.toLowerCase()) ||
    p.code.toLowerCase().includes(searchTerm.value.toLowerCase())
  );
}

// Override getter/setter helper
function getOverrideValue(permId) {
  return form.overrides[permId] || 'default';
}

function setOverride(permId, value) {
  form.overrides[permId] = value;
}

// Real-time computed permissions engine
const isUserSuperAdmin = computed(() => {
  const superAdminRole = props.roles.find(r => r.code === 'super_admin');
  if (!superAdminRole) return false;
  return form.role_ids.includes(superAdminRole.id);
});

const activeComputedPermissions = computed(() => {
  if (isUserSuperAdmin.value) {
    return props.allPermissions.map(p => p.code);
  }

  // 1. Gather all permissions from selected roles
  const selectedRoles = props.roles.filter(r => form.role_ids.includes(r.id));
  const rolePermissionCodes = new Set();
  
  selectedRoles.forEach(r => {
    // Spatie seeder uses role.permissions as array of codes in our mock
    if (r.permissions) {
      r.permissions.forEach(code => rolePermissionCodes.add(code));
    }
  });

  // 2. Apply allow & deny overrides
  const finalCodes = new Set(rolePermissionCodes);

  Object.entries(form.overrides).forEach(([permId, effect]) => {
    const perm = props.allPermissions.find(p => p.id == permId);
    if (!perm) return;

    if (effect === 'deny') {
      finalCodes.delete(perm.code);
    } else if (effect === 'allow') {
      finalCodes.add(perm.code);
    }
  });

  return Array.from(finalCodes);
});

const computedGroupedPermissions = computed(() => {
  const codes = activeComputedPermissions.value;
  const list = props.allPermissions.filter(p => codes.includes(p.code));
  
  const grouped = {};
  list.forEach(p => {
    // Translate module names
    const modLabel = p.module.toUpperCase();
    if (!grouped[modLabel]) grouped[modLabel] = [];
    grouped[modLabel].push(p);
  });
  return grouped;
});

const submit = () => {
  if (props.user) {
    form.put(route('admin.users.update', props.user.id));
  } else {
    form.post(route('admin.users.store'));
  }
};
</script>
