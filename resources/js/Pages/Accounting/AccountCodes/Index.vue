<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Hệ thống tài khoản kế toán</h1>
          <p class="text-sm text-gray-500 mt-0.5">Thông tư 133/2016/TT-BTC — Chế độ kế toán doanh nghiệp nhỏ và vừa</p>
        </div>
        <div class="flex items-center gap-2">
          <a v-if="can('accounting.manage')" :href="route('accounting.account-codes.sample')"
            class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            File mẫu
          </a>
          <button v-if="can('accounting.manage')" @click="showImport = true"
            class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Import Excel
          </button>
          <button v-if="can('accounting.manage')" @click="showAddForm = true"
            class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Thêm tài khoản
          </button>
        </div>
      </div>

      <!-- Filter -->
      <div class="flex gap-3">
        <input v-model="search" placeholder="Tìm mã hoặc tên tài khoản..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-72 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="filterType"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả loại</option>
          <option value="asset">Tài sản</option>
          <option value="liability">Nợ phải trả</option>
          <option value="equity">Vốn chủ sở hữu</option>
          <option value="revenue">Doanh thu</option>
          <option value="expense">Chi phí</option>
          <option value="contra">Điều chỉnh</option>
        </select>
        <label class="flex items-center gap-2 text-sm text-gray-600">
          <input type="checkbox" v-model="showDetail" class="rounded" />
          Chỉ tài khoản chi tiết
        </label>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600 w-32">Mã TK</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên tài khoản</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600 w-40">Loại</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600 w-24">Dư nợ/có</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600 w-24">Chi tiết</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600 w-20">Trạng thái</th>
              <th v-if="can('accounting.manage')" class="px-5 py-3 w-20" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="acc in filteredAccounts" :key="acc.code"
              :class="[rowBg(acc), 'hover:bg-gray-50 transition-colors']">
              <td class="px-5 py-2.5 font-mono font-semibold text-gray-800" :style="`padding-left: ${(acc.level - 1) * 20 + 20}px`">
                {{ acc.code }}
              </td>
              <td class="px-5 py-2.5" :class="acc.level === 1 ? 'font-bold text-gray-900' : acc.level === 2 ? 'font-semibold text-gray-800' : 'text-gray-700'">
                {{ acc.name }}
              </td>
              <td class="px-5 py-2.5">
                <span :class="typeBadge(acc.type)" class="inline-flex px-2 py-0.5 rounded text-xs font-medium">
                  {{ acc.type_label }}
                </span>
              </td>
              <td class="px-5 py-2.5 text-xs text-gray-600">
                {{ acc.normal_balance === 'debit' ? 'Dư Nợ' : 'Dư Có' }}
              </td>
              <td class="px-5 py-2.5">
                <span v-if="acc.is_detail"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                  Chi tiết
                </span>
              </td>
              <td class="px-5 py-2.5">
                <span :class="acc.is_active ? 'text-green-600' : 'text-gray-400'" class="text-xs font-medium">
                  {{ acc.is_active ? 'Hoạt động' : 'Tạm khóa' }}
                </span>
              </td>
              <td v-if="can('accounting.manage')" class="px-5 py-2.5 text-right">
                <button @click="editAccount(acc)" class="text-primary-600 hover:text-primary-800 text-xs font-medium">Sửa</button>
              </td>
            </tr>
            <tr v-if="!filteredAccounts.length">
              <td colspan="7" class="px-5 py-10 text-center text-gray-400">Không tìm thấy tài khoản nào</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-gray-400">{{ filteredAccounts.length }} / {{ accounts.length }} tài khoản</p>
    </div>

    <!-- Modal: Import Excel -->
    <div v-if="showImport" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h3 class="font-semibold text-gray-900">Import danh mục tài khoản</h3>
          <button @click="closeImport" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="p-6 space-y-4">
          <p class="text-sm text-gray-500">
            Upload file Excel theo đúng format. Tài khoản đã tồn tại sẽ được <strong>cập nhật</strong>,
            tài khoản mới sẽ được <strong>tạo thêm</strong>. Không xóa tài khoản cũ.
          </p>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">File Excel (.xls / .xlsx)</label>
            <input ref="importFileRef" type="file" accept=".xls,.xlsx"
              class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200"
              @change="onFileChange" />
          </div>
          <div v-if="importError" class="text-sm text-red-600 bg-red-50 px-3 py-2 rounded-lg">{{ importError }}</div>
          <div class="flex justify-end gap-3 pt-1">
            <button @click="closeImport" class="erp-btn-secondary text-sm">Hủy</button>
            <button @click="submitImport" :disabled="!importFile || importing"
              class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg text-sm font-medium disabled:opacity-50">
              {{ importing ? 'Đang import...' : 'Import' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: Add/Edit account -->
    <div v-if="showAddForm || editingAccount" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h3 class="font-semibold text-gray-900">{{ editingAccount ? 'Sửa tài khoản' : 'Thêm tài khoản' }}</h3>
          <button @click="closeModal" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <form @submit.prevent="submitForm" class="p-6 space-y-4">
          <template v-if="!editingAccount">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mã tài khoản *</label>
                <input v-model="form.code" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tài khoản cha</label>
                <select v-model="form.parent_code" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                  <option value="">— Không có —</option>
                  <option v-for="a in accounts" :key="a.code" :value="a.code">{{ a.code }} — {{ a.name }}</option>
                </select>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Loại tài khoản *</label>
              <select v-model="form.type" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="asset">Tài sản</option>
                <option value="liability">Nợ phải trả</option>
                <option value="equity">Vốn chủ sở hữu</option>
                <option value="revenue">Doanh thu</option>
                <option value="expense">Chi phí</option>
                <option value="contra">Tài khoản điều chỉnh</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Dư bình thường *</label>
              <div class="flex gap-4">
                <label class="flex items-center gap-2 text-sm"><input type="radio" v-model="form.normal_balance" value="debit" /> Dư Nợ</label>
                <label class="flex items-center gap-2 text-sm"><input type="radio" v-model="form.normal_balance" value="credit" /> Dư Có</label>
              </div>
            </div>
          </template>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên tài khoản *</label>
            <input v-model="form.name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>

          <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
              <input type="checkbox" v-model="form.is_detail" class="rounded" />
              Tài khoản chi tiết (có thể hạch toán)
            </label>
            <template v-if="editingAccount">
              <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                <input type="checkbox" v-model="form.is_active" class="rounded" />
                Đang hoạt động
              </label>
            </template>
          </div>

          <div class="flex justify-end gap-3 pt-2">
            <button type="button" @click="closeModal" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Hủy</button>
            <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50">
              {{ editingAccount ? 'Cập nhật' : 'Thêm tài khoản' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ accounts: Array });
const { hasPermission: can } = usePermission();

const search       = ref('');
const filterType   = ref('');
const showDetail   = ref(false);
const showAddForm  = ref(false);
const editingAccount = ref(null);

// ─── Import Excel ─────────────────────────────────────────────────────────────
const showImport    = ref(false);
const importFile    = ref(null);
const importError   = ref('');
const importing     = ref(false);
const importFileRef = ref(null);

function onFileChange(e) {
  importFile.value  = e.target.files[0] ?? null;
  importError.value = '';
}
function closeImport() {
  showImport.value  = false;
  importFile.value  = null;
  importError.value = '';
  if (importFileRef.value) importFileRef.value.value = '';
}
function submitImport() {
  if (!importFile.value) return;
  importing.value   = true;
  importError.value = '';
  const fd = new FormData();
  fd.append('excel_file', importFile.value);
  router.post(route('accounting.account-codes.import'), fd, {
    forceFormData: true,
    onSuccess: () => closeImport(),
    onError: (errors) => { importError.value = errors.excel_file ?? 'Import thất bại.'; },
    onFinish: () => { importing.value = false; },
  });
}

const form = useForm({
  code: '', name: '', type: 'asset', normal_balance: 'debit',
  parent_code: '', is_detail: false, is_active: true,
});

const filteredAccounts = computed(() => {
  return props.accounts.filter(a => {
    if (filterType.value && a.type !== filterType.value) return false;
    if (showDetail.value && !a.is_detail) return false;
    if (search.value) {
      const q = search.value.toLowerCase();
      return a.code.toLowerCase().includes(q) || a.name.toLowerCase().includes(q);
    }
    return true;
  });
});

function rowBg(acc) {
  if (acc.level === 1) return 'bg-gray-50';
  if (acc.level === 2) return 'bg-white';
  return '';
}

function typeBadge(type) {
  const map = {
    asset: 'bg-blue-100 text-blue-700',
    liability: 'bg-red-100 text-red-700',
    equity: 'bg-purple-100 text-purple-700',
    revenue: 'bg-green-100 text-green-700',
    expense: 'bg-orange-100 text-orange-700',
    contra: 'bg-gray-100 text-gray-600',
  };
  return map[type] ?? 'bg-gray-100 text-gray-600';
}

function editAccount(acc) {
  editingAccount.value = acc;
  form.name      = acc.name;
  form.is_detail = acc.is_detail;
  form.is_active = acc.is_active;
}

function closeModal() {
  showAddForm.value  = false;
  editingAccount.value = null;
  form.reset();
}

function submitForm() {
  if (editingAccount.value) {
    form.put(route('accounting.account-codes.update', editingAccount.value.code), {
      onSuccess: () => closeModal(),
    });
  } else {
    form.post(route('accounting.account-codes.store'), {
      onSuccess: () => closeModal(),
    });
  }
}
</script>
