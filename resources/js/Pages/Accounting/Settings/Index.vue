<template>
  <AppLayout>
    <div class="max-w-4xl space-y-6">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-900">Cài đặt Tài khoản Kế toán</h1>
        <button @click="saveAll" :disabled="form.processing"
          class="bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white px-5 py-2 rounded-lg text-sm font-medium">
          {{ form.processing ? 'Đang lưu...' : 'Lưu tất cả' }}
        </button>
      </div>

      <p class="text-sm text-gray-500">
        Cấu hình tài khoản kế toán mặc định được dùng khi hệ thống tự động hạch toán.
        Khi để trống, hệ thống dùng tài khoản mặc định theo chế độ kế toán Việt Nam (TT133).
      </p>

      <div v-if="$page.props.flash?.success"
        class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 text-sm text-green-800">
        {{ $page.props.flash.success }}
      </div>

      <!-- Nhóm settings -->
      <div v-for="group in groups" :key="group.key" class="bg-white rounded-xl border border-gray-200">
        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50 rounded-t-xl">
          <h2 class="text-sm font-semibold text-gray-700">{{ group.label }}</h2>
        </div>
        <div class="divide-y divide-gray-100">
          <div v-for="setting in group.settings" :key="setting.key"
            class="grid grid-cols-1 sm:grid-cols-2 gap-3 px-5 py-4 items-start">
            <div>
              <p class="text-sm font-medium text-gray-800">{{ setting.label }}</p>
              <p v-if="setting.description" class="text-xs text-gray-400 mt-0.5">{{ setting.description }}</p>
            </div>
            <div>
              <select v-model="localSettings[setting.key]"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">— Dùng mặc định hệ thống —</option>
                <optgroup v-for="ag in accountGroups" :key="ag.label" :label="ag.label">
                  <option v-for="a in ag.accounts" :key="a.code" :value="a.code">
                    {{ a.code }} — {{ a.name }}
                  </option>
                </optgroup>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="flex justify-end">
        <button @click="saveAll" :disabled="form.processing"
          class="bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white px-6 py-2 rounded-lg text-sm font-medium">
          {{ form.processing ? 'Đang lưu...' : 'Lưu tất cả' }}
        </button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  groups:   Array,
  accounts: Array,
});

// Flatten tất cả settings thành map key→value để dễ bind
const localSettings = reactive({});
for (const group of props.groups) {
  for (const s of group.settings) {
    localSettings[s.key] = s.value ?? '';
  }
}

const typeLabels = {
  asset:     'Loại 1/2 — Tài sản',
  liability: 'Loại 3 — Nợ phải trả',
  equity:    'Loại 4 — Vốn chủ sở hữu',
  revenue:   'Loại 5 — Doanh thu',
  expense:   'Loại 6/8 — Chi phí',
  contra:    'Tài khoản điều chỉnh',
};

const accountGroups = computed(() => {
  const map = {};
  for (const a of props.accounts) {
    const label = typeLabels[a.type] ?? a.type;
    if (!map[label]) map[label] = { label, accounts: [] };
    map[label].accounts.push(a);
  }
  return Object.values(map);
});

const form = useForm({});

function saveAll() {
  const settings = Object.entries(localSettings).map(([key, value]) => ({ key, value: value || null }));
  form.transform(() => ({ settings }))
    .put(route('accounting.settings.update'));
}
</script>
