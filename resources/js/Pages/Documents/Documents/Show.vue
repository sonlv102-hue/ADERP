<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('documents.documents.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Link>
          <div>
            <div class="flex items-center gap-3">
              <h1 class="text-2xl font-bold text-gray-900">{{ document.code }}</h1>
              <StatusBadge :color="document.status_color">{{ document.status_label }}</StatusBadge>
            </div>
            <p class="text-sm text-gray-500 mt-0.5">{{ document.type_name }}</p>
          </div>
        </div>
        <div class="flex gap-2">
          <a v-if="document.file_url" :href="route('documents.documents.download', document.id)"
            class="flex items-center gap-2 border border-blue-300 text-blue-700 hover:bg-blue-50 px-4 py-2 rounded-lg text-sm font-medium">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Tải file
          </a>
          <Link v-if="can('documents.manage')" :href="route('documents.documents.edit', document.id)"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Chỉnh sửa
          </Link>
          <button v-if="can('documents.manage')" @click="confirmDelete"
            class="border border-red-300 text-red-700 hover:bg-red-50 px-4 py-2 rounded-lg text-sm font-medium">
            Xoá
          </button>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-5">
        <!-- Thông tin chứng từ -->
        <div class="col-span-2 space-y-5">
          <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-base font-semibold text-gray-800 mb-4">Thông tin chứng từ</h2>
            <dl class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm">
              <div>
                <dt class="text-gray-500">Tiêu đề</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ document.title }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Loại chứng từ</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ document.type_name }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Ngày phát hành</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ document.issued_date ?? '—' }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Ngày hết hạn</dt>
                <dd class="font-medium mt-0.5" :class="isExpiringSoon ? 'text-orange-600' : 'text-gray-900'">
                  {{ document.expired_date ?? '—' }}
                  <span v-if="isExpiringSoon" class="text-xs ml-1">(sắp hết hạn)</span>
                </dd>
              </div>
              <div>
                <dt class="text-gray-500">Người tải lên</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ document.uploader }}</dd>
              </div>
              <div>
                <dt class="text-gray-500">Ngày tạo</dt>
                <dd class="font-medium text-gray-900 mt-0.5">{{ document.created_at }}</dd>
              </div>
              <div v-if="document.note" class="col-span-2">
                <dt class="text-gray-500">Ghi chú</dt>
                <dd class="text-gray-900 mt-0.5">{{ document.note }}</dd>
              </div>
            </dl>
          </div>

          <!-- File -->
          <div class="bg-white rounded-xl border border-gray-200 p-6">
            <h2 class="text-base font-semibold text-gray-800 mb-4">File đính kèm</h2>
            <div v-if="document.file_name" class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
              <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                :class="fileIconBg">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ document.file_name }}</p>
                <p class="text-xs text-gray-500 mt-0.5">{{ document.file_size_human }}</p>
              </div>
              <a :href="route('documents.documents.download', document.id)"
                class="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                Tải xuống
              </a>
            </div>
            <p v-else class="text-sm text-gray-400 text-center py-4">Chưa có file đính kèm</p>
          </div>
        </div>

        <!-- Gắn với nghiệp vụ -->
        <div class="space-y-5">
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-800 mb-3">Liên kết nghiệp vụ</h2>

            <ul v-if="document.relations.length" class="space-y-2 mb-4">
              <li v-for="r in document.relations" :key="r.id"
                class="flex items-center justify-between text-sm p-2 bg-gray-50 rounded-lg">
                <div>
                  <span class="text-gray-500 text-xs">{{ r.type_label }}</span>
                  <p class="font-medium text-gray-900">{{ r.related_label || '#' + r.related_id }}</p>
                </div>
                <button v-if="can('documents.manage')" @click="detachRelation(r.id)"
                  class="text-gray-400 hover:text-red-600 ml-2">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </li>
            </ul>
            <p v-else class="text-xs text-gray-400 mb-3">Chưa gắn với nghiệp vụ nào</p>

            <!-- Add relation -->
            <div v-if="can('documents.create')" class="border-t border-gray-100 pt-3 space-y-2">
              <p class="text-xs font-medium text-gray-600">Thêm liên kết</p>
              <select v-model="newRelType"
                class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500">
                <option value="">-- Loại nghiệp vụ --</option>
                <option v-for="r in related_types" :key="r.value" :value="r.value">{{ r.label }}</option>
              </select>
              <input v-model="newRelId" type="number" min="1" placeholder="ID nghiệp vụ..."
                class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500" />
              <button @click="addRelation" :disabled="!newRelType || !newRelId"
                class="w-full bg-primary-600 hover:bg-primary-700 text-white py-1.5 rounded-lg text-xs font-medium disabled:opacity-40">
                Gắn
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({
  document:      Object,
  related_types: Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;

const newRelType = ref('');
const newRelId   = ref('');

const isExpiringSoon = computed(() => {
  if (!props.document.expired_date || props.document.status !== 'active') return false;
  const parts = props.document.expired_date.split('/');
  const exp = new Date(parts[2], parts[1] - 1, parts[0]);
  const diff = (exp - new Date()) / (1000 * 60 * 60 * 24);
  return diff >= 0 && diff <= 30;
});

const fileIconBg = computed(() => {
  const t = props.document.file_type ?? '';
  if (t.includes('pdf'))   return 'bg-red-500';
  if (t.includes('word') || t.includes('document')) return 'bg-blue-600';
  if (t.includes('excel') || t.includes('sheet'))   return 'bg-green-600';
  if (t.includes('image')) return 'bg-purple-500';
  return 'bg-gray-500';
});

function addRelation() {
  if (!newRelType.value || !newRelId.value) return;
  router.post(route('documents.documents.attach', props.document.id), {
    related_type: newRelType.value,
    related_id:   Number(newRelId.value),
  }, {
    onSuccess: () => { newRelType.value = ''; newRelId.value = ''; },
  });
}

function detachRelation(relationId) {
  if (!confirm('Gỡ liên kết này?')) return;
  router.post(route('documents.documents.detach', props.document.id), {
    relation_id: relationId,
  });
}

function confirmDelete() {
  if (!confirm(`Xoá chứng từ ${props.document.code}? Hành động này không thể hoàn tác.`)) return;
  router.delete(route('documents.documents.destroy', props.document.id));
}
</script>
