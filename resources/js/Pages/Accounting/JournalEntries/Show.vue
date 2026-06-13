<template>
  <AppLayout>
    <div class="max-w-3xl mx-auto space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.journal-entries.index')" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ entry.code }}</h1>
            <p class="text-sm text-gray-500">{{ entry.entry_date }}</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <StatusBadge :color="entry.status_color">{{ entry.status_label }}</StatusBadge>
          <span v-if="entry.is_auto" class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-600 border border-blue-200">
            Tự động
          </span>
          <button v-if="entry.status !== 'voided' && can('accounting.manage')"
            @click="openEditModal"
            class="text-sm px-3 py-1.5 border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50">
            Sửa diễn giải
          </button>
          <button v-if="entry.status === 'draft' && can('accounting.manage')"
            @click="showPostModal = true"
            class="text-sm px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700">
            Duyệt & Hạch toán
          </button>
          <button v-if="entry.status === 'draft' && can('accounting.manage')"
            @click="showDeleteDraftModal = true"
            class="text-sm px-3 py-1.5 border border-red-300 text-red-600 rounded-lg hover:bg-red-50">
            Xóa
          </button>
          <template v-if="entry.status === 'posted' && can('accounting.manage')">
            <button v-if="!entry.description.startsWith('Đảo:')"
              @click="showReverseModal = true"
              class="text-sm px-3 py-1.5 border border-orange-300 text-orange-600 rounded-lg hover:bg-orange-50">
              Đảo bút toán
            </button>
            <button v-if="!entry.period_locked"
              @click="showVoidModal = true"
              class="text-sm px-3 py-1.5 border border-red-300 text-red-600 rounded-lg hover:bg-red-50">
              Hủy bút toán
            </button>
            <span v-else class="text-xs text-gray-400 italic">Kỳ đã khóa sổ</span>
          </template>
          <template v-if="entry.status === 'reversed' && can('accounting.manage')">
            <button v-if="!entry.period_locked"
              @click="showVoidModal = true"
              class="text-sm px-3 py-1.5 border border-red-300 text-red-600 rounded-lg hover:bg-red-50">
              Hủy cặp bút toán
            </button>
            <span v-else class="text-xs text-gray-400 italic">Kỳ đã khóa sổ</span>
          </template>
        </div>
      </div>

      <!-- Info card -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <dl class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt class="text-gray-500">Ngày hạch toán</dt>
            <dd class="font-medium text-gray-900 mt-1">{{ entry.entry_date }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">Người tạo</dt>
            <dd class="font-medium text-gray-900 mt-1">{{ entry.creator }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">Diễn giải</dt>
            <dd class="font-medium text-gray-900 mt-1">{{ entry.description }}</dd>
          </div>
          <div v-if="entry.posted_at">
            <dt class="text-gray-500">Hạch toán lúc</dt>
            <dd class="font-medium text-gray-900 mt-1">{{ entry.posted_at }}</dd>
          </div>
          <div v-if="entry.reference_type" class="col-span-2">
            <dt class="text-gray-500">Chứng từ gốc</dt>
            <dd class="font-medium text-gray-900 mt-1">{{ entry.reference_type }} #{{ entry.reference_id }}</dd>
          </div>
          <div v-if="entry.notes" class="col-span-2">
            <dt class="text-gray-500">Ghi chú</dt>
            <dd class="text-gray-700 mt-1">{{ entry.notes }}</dd>
          </div>
          <!-- Void metadata -->
          <template v-if="entry.status === 'voided'">
            <div>
              <dt class="text-gray-500">Hủy lúc</dt>
              <dd class="font-medium text-gray-900 mt-1">{{ entry.voided_at }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Người hủy</dt>
              <dd class="font-medium text-gray-900 mt-1">{{ entry.voided_by ?? '—' }}</dd>
            </div>
            <div v-if="entry.void_reason" class="col-span-2">
              <dt class="text-gray-500">Lý do hủy</dt>
              <dd class="text-gray-700 mt-1">{{ entry.void_reason }}</dd>
            </div>
          </template>
        </dl>
        <!-- Voided banner -->
        <div v-if="entry.status === 'voided'" class="mt-4 bg-slate-50 border border-slate-200 rounded-lg px-4 py-3 text-sm text-slate-600">
          Bút toán này đã được hủy và không còn ảnh hưởng đến sổ cái hay báo cáo tài chính. Dữ liệu được giữ lại để phục vụ tra cứu lịch sử.
        </div>
      </div>

      <!-- Journal Lines -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
          <h3 class="font-semibold text-gray-700 text-sm">Các dòng bút toán</h3>
        </div>
        <table class="w-full text-sm" :class="entry.status === 'voided' ? 'opacity-50' : ''">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600 w-36">Tài khoản</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên tài khoản</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Diễn giải</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600 w-36">Nợ</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600 w-36">Có</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="line in entry.lines" :key="line.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono font-semibold text-gray-800">{{ line.account_code }}</td>
              <td class="px-5 py-3 text-gray-700">{{ line.account_name }}</td>
              <td class="px-5 py-3 text-gray-500 text-xs">{{ line.description ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium" :class="line.debit > 0 ? 'text-gray-800' : 'text-gray-300'">
                {{ line.debit > 0 ? formatVnd(line.debit) : '—' }}
              </td>
              <td class="px-5 py-3 text-right font-medium" :class="line.credit > 0 ? 'text-gray-800' : 'text-gray-300'">
                {{ line.credit > 0 ? formatVnd(line.credit) : '—' }}
              </td>
            </tr>
          </tbody>
          <tfoot class="bg-gray-50 border-t-2 border-gray-300">
            <tr>
              <td colspan="3" class="px-5 py-3 font-bold text-gray-700 text-sm">Tổng cộng</td>
              <td class="px-5 py-3 text-right font-bold text-gray-900">{{ formatVnd(entry.total_debit) }}</td>
              <td class="px-5 py-3 text-right font-bold text-gray-900">{{ formatVnd(entry.total_credit) }}</td>
            </tr>
          </tfoot>
        </table>
        <div v-if="Math.abs(entry.total_debit - entry.total_credit) > 1"
          class="px-5 py-3 bg-red-50 text-red-600 text-sm font-medium border-t border-red-200">
          ⚠ Bút toán không cân: Nợ={{ formatVnd(entry.total_debit) }} / Có={{ formatVnd(entry.total_credit) }}
        </div>
      </div>
    </div>

    <!-- Modal: Duyệt bút toán -->
    <div v-if="showPostModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="font-semibold text-gray-900">Duyệt bút toán {{ entry.code }}</h3>
        </div>
        <div class="p-6 space-y-3 text-sm text-gray-600">
          <p>Xác nhận duyệt và hạch toán bút toán này?</p>
          <p class="text-blue-600">Sau khi duyệt, bút toán sẽ có hiệu lực và ảnh hưởng đến báo cáo kế toán.</p>
        </div>
        <div class="px-6 py-4 flex justify-end gap-3 border-t border-gray-100">
          <button @click="showPostModal = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700">Hủy</button>
          <button @click="submitPost" class="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700">Duyệt & Hạch toán</button>
        </div>
      </div>
    </div>

    <!-- Modal: Xóa bút toán nháp -->
    <div v-if="showDeleteDraftModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="font-semibold text-gray-900">Xóa bút toán {{ entry.code }}</h3>
        </div>
        <div class="p-6 space-y-3 text-sm text-gray-700">
          <p>Bạn có chắc muốn xóa bút toán nháp này?</p>
          <p class="text-red-600 bg-red-50 px-3 py-2 rounded-lg font-medium">Không thể hoàn tác sau khi xóa.</p>
        </div>
        <div class="flex justify-end gap-3 px-6 pb-6">
          <button @click="showDeleteDraftModal = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700">Hủy</button>
          <button @click="submitDeleteDraft" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">Xóa bút toán</button>
        </div>
      </div>
    </div>

    <!-- Modal: Hủy bút toán (posted hoặc reversed) -->
    <div v-if="showVoidModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="font-semibold text-gray-900">
            {{ entry.status === 'reversed' ? 'Hủy cặp bút toán' : 'Hủy bút toán' }} {{ entry.code }}
          </h3>
        </div>
        <form @submit.prevent="submitVoid" class="p-6 space-y-4 text-sm text-gray-700">
          <template v-if="entry.status === 'reversed'">
            <p>Bút toán này đã được đảo.</p>
            <p>Khi hủy, hệ thống sẽ hủy cả bút toán gốc và bút toán đảo ngược liên quan. Hai bút toán sẽ không còn ảnh hưởng đến sổ cái và báo cáo, nhưng vẫn được lưu lại để phục vụ truy vết.</p>
            <p>Bạn có chắc chắn muốn tiếp tục?</p>
          </template>
          <template v-else>
            <p>Bút toán này sẽ được hủy và không còn ảnh hưởng đến sổ cái và báo cáo tài chính. Dữ liệu sẽ được giữ lại để tra cứu lịch sử.</p>
          </template>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Lý do hủy (tùy chọn)</label>
            <textarea v-model="voidForm.void_reason" rows="2" maxlength="500"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div class="flex justify-end gap-3 pt-2">
            <button type="button" @click="showVoidModal = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700">Không</button>
            <button type="submit" :disabled="voidForm.processing" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50">
              {{ entry.status === 'reversed' ? 'Hủy cặp bút toán' : 'Hủy bút toán' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Sửa diễn giải / ghi chú -->
    <div v-if="showEditModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="font-semibold text-gray-900">Sửa bút toán {{ entry.code }}</h3>
        </div>
        <form @submit.prevent="submitEdit" class="p-6 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải <span class="text-red-500">*</span></label>
            <input v-model="editForm.description" type="text" maxlength="500"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <p v-if="editForm.errors.description" class="mt-1 text-xs text-red-600">{{ editForm.errors.description }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="editForm.notes" rows="3" maxlength="1000"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <p class="text-xs text-amber-600 bg-amber-50 px-3 py-2 rounded-lg">
            Chỉ có thể sửa diễn giải và ghi chú. Dòng bút toán và số tiền không thay đổi.
          </p>
          <div class="flex justify-end gap-3">
            <button type="button" @click="showEditModal = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700">Hủy</button>
            <button type="submit" :disabled="editForm.processing" class="px-4 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50">
              Lưu
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: Đảo bút toán -->
    <div v-if="showReverseModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="font-semibold text-gray-900">Đảo bút toán {{ entry.code }}</h3>
        </div>
        <form @submit.prevent="submitReverse" class="p-6 space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Lý do đảo (tùy chọn)</label>
            <textarea v-model="reverseForm.reason" rows="3"
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div class="flex justify-end gap-3">
            <button type="button" @click="showReverseModal = false" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700">Hủy</button>
            <button type="submit" :disabled="reverseForm.processing" class="px-4 py-2 text-sm bg-orange-600 text-white rounded-lg hover:bg-orange-700 disabled:opacity-50">
              Xác nhận đảo
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ entry: Object });
const { hasPermission: can } = usePermission();
const { formatVnd } = useCurrency();

const showEditModal = ref(false);
const editForm = useForm({ description: '', notes: '' });

function openEditModal() {
  editForm.description = props.entry.description;
  editForm.notes = props.entry.notes ?? '';
  showEditModal.value = true;
}

function submitEdit() {
  editForm.patch(route('accounting.journal-entries.update', props.entry.id), {
    onSuccess: () => { showEditModal.value = false; },
  });
}

const showPostModal = ref(false);

function submitPost() {
  router.post(route('accounting.journal-entries.post', props.entry.id), {}, {
    onSuccess: () => { showPostModal.value = false; },
  });
}

const showReverseModal = ref(false);
const reverseForm = useForm({ reason: '' });

function submitReverse() {
  reverseForm.post(route('accounting.journal-entries.reverse', props.entry.id), {
    onSuccess: () => { showReverseModal.value = false; },
  });
}

const showDeleteDraftModal = ref(false);

function submitDeleteDraft() {
  router.delete(route('accounting.journal-entries.destroy', props.entry.id));
}

const showVoidModal = ref(false);
const voidForm = useForm({ void_reason: '' });

function submitVoid() {
  voidForm.post(route('accounting.journal-entries.void', props.entry.id), {
    onSuccess: () => { showVoidModal.value = false; },
  });
}
</script>
