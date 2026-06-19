<template>
  <AppLayout>
    <div class="max-w-3xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="backUrl" class="text-gray-400 hover:text-gray-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ isEditing ? 'Sửa bút toán ' + entry.code : 'Tạo bút toán thủ công' }}
        </h1>
      </div>

      <!-- Warning: editing auto-generated entry -->
      <div v-if="isEditing && entry.is_auto"
        class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800">
        <p class="font-semibold mb-1">Bút toán tự động</p>
        <p>Sửa dòng bút toán tự động sẽ được ghi nhận là điều chỉnh thủ công. Bản gốc được lưu để có thể khôi phục sau.</p>
      </div>

      <form @submit.prevent="" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <!-- Header fields -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã bút toán</label>
            <input :value="isEditing ? entry.code : nextCode" disabled
              class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày hạch toán *</label>
            <input v-model="form.entry_date" type="date" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải *</label>
          <input v-model="form.description" required placeholder="Nội dung bút toán..."
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <!-- Journal Lines -->
        <div>
          <div class="flex items-center justify-between mb-3">
            <label class="text-sm font-semibold text-gray-700">Các dòng bút toán (Nợ / Có)</label>
            <button type="button" @click="addLine"
              class="text-primary-600 hover:text-primary-800 text-sm font-medium flex items-center gap-1">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Thêm dòng
            </button>
          </div>

          <div class="overflow-hidden rounded-lg border border-gray-200">
            <table class="min-w-full text-sm">
              <thead class="bg-gray-50">
                <tr>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600 w-44">Tài khoản</th>
                  <th class="text-left px-3 py-2 font-semibold text-gray-600">Diễn giải dòng</th>
                  <th class="text-right px-3 py-2 font-semibold text-gray-600 w-36">Nợ (Debit)</th>
                  <th class="text-right px-3 py-2 font-semibold text-gray-600 w-36">Có (Credit)</th>
                  <th class="px-3 py-2 w-8" />
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(line, i) in form.lines" :key="i">
                  <td class="px-3 py-2">
                    <RemoteSearchSelect
                      v-model="line.account_code"
                      :search-url="route('search.account-codes')"
                      :extra-params="{ detail_only: 1 }"
                      :display-text="line._accountDisplay"
                      placeholder="— Tìm TK —"
                      empty-text="Không tìm thấy tài khoản"
                      @change="opt => onAccountSelect(line, opt)"
                    />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model="line.description" placeholder="Diễn giải..."
                      class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary-500" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.number="line.debit" type="number" min="0" step="any" @input="line.credit = 0"
                      class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs text-right focus:outline-none focus:ring-1 focus:ring-primary-500" />
                  </td>
                  <td class="px-3 py-2">
                    <input v-model.number="line.credit" type="number" min="0" step="any" @input="line.debit = 0"
                      class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs text-right focus:outline-none focus:ring-1 focus:ring-primary-500" />
                  </td>
                  <td class="px-3 py-2 text-center">
                    <button type="button" v-if="form.lines.length > 2" @click="removeLine(i)"
                      class="text-red-400 hover:text-red-600">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                <tr>
                  <td colspan="2" class="px-3 py-2 text-sm font-semibold text-gray-700">Tổng cộng</td>
                  <td class="px-3 py-2 text-right text-sm font-bold" :class="isBalanced ? 'text-gray-800' : 'text-red-600'">
                    {{ formatVnd(totalDebit) }}
                  </td>
                  <td class="px-3 py-2 text-right text-sm font-bold" :class="isBalanced ? 'text-gray-800' : 'text-red-600'">
                    {{ formatVnd(totalCredit) }}
                  </td>
                  <td />
                </tr>
              </tfoot>
            </table>
          </div>
          <p v-if="!isBalanced" class="text-red-600 text-xs mt-2 font-medium">
            ⚠ Bút toán chưa cân: Nợ ≠ Có. Chênh lệch {{ formatVnd(Math.abs(totalDebit - totalCredit)) }}
          </p>
          <p v-if="form.errors.lines" class="text-red-600 text-xs mt-2">{{ form.errors.lines }}</p>
        </div>

        <!-- Edit reason (edit mode, auto entries only) -->
        <div v-if="isEditing && entry.is_auto">
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Lý do điều chỉnh
            <span class="text-gray-400 font-normal">(ghi rõ nếu có sửa dòng bút toán)</span>
          </label>
          <textarea v-model="form.edit_reason" rows="2" maxlength="500"
            placeholder="Lý do điều chỉnh dòng bút toán..."
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="2"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
          <Link :href="backUrl"
            class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
            Hủy
          </Link>
          <!-- Edit mode: one save button -->
          <template v-if="isEditing">
            <button type="button" @click="submitEdit"
              :disabled="form.processing || !isBalanced"
              class="px-5 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50">
              {{ form.processing ? 'Đang lưu...' : 'Lưu thay đổi' }}
            </button>
          </template>
          <!-- Create mode: save draft OR post immediately -->
          <template v-else>
            <button type="button" @click="submitDraft"
              :disabled="form.processing || !isBalanced"
              class="px-5 py-2 text-sm border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 disabled:opacity-50">
              Lưu nháp
            </button>
            <button type="button" @click="submitPost"
              :disabled="form.processing || !isBalanced"
              class="px-5 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50">
              {{ form.processing ? 'Đang xử lý...' : 'Hạch toán' }}
            </button>
          </template>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  nextCode: String,
  entry:    { type: Object, default: null },
});

const { formatVnd } = useCurrency();

const isEditing = computed(() => !!props.entry);
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  entry_date:    props.entry?.entry_date ?? today,
  description:   props.entry?.description ?? '',
  notes:         props.entry?.notes ?? '',
  edit_reason:   '',
  save_as_draft: false,
  lines: props.entry?.lines?.length
    ? props.entry.lines.map(l => ({
        account_code:    l.account_code,
        _accountDisplay: l.account_code && l.account_name ? `${l.account_code} - ${l.account_name}` : (l.account_code ?? ''),
        description:     l.description ?? '',
        debit:           l.debit,
        credit:          l.credit,
      }))
    : [
        { account_code: '', _accountDisplay: '', description: '', debit: 0, credit: 0 },
        { account_code: '', _accountDisplay: '', description: '', debit: 0, credit: 0 },
      ],
});

const backUrl = computed(() =>
  isEditing.value
    ? route('accounting.journal-entries.show', props.entry.id)
    : route('accounting.journal-entries.index')
);

const typeLabels = {
  asset:     'Loại 1/2 — Tài sản',
  liability: 'Loại 3 — Nợ phải trả',
  equity:    'Loại 4 — Vốn chủ sở hữu',
  revenue:   'Loại 5 — Doanh thu',
  expense:   'Loại 6/8 — Chi phí',
  contra:    'Tài khoản điều chỉnh',
};

const totalDebit  = computed(() => form.lines.reduce((s, l) => s + (Number(l.debit)  || 0), 0));
const totalCredit = computed(() => form.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0));
const isBalanced  = computed(() => Math.abs(totalDebit.value - totalCredit.value) < 1 && totalDebit.value > 0);

function addLine() {
  form.lines.push({ account_code: '', _accountDisplay: '', description: '', debit: 0, credit: 0 });
}
function removeLine(i) {
  form.lines.splice(i, 1);
}

function onAccountSelect(line, opt) {
  if (!opt) return;
  line._accountDisplay = opt.code ? `${opt.code} - ${opt.label}` : opt.label;
}

function submitEdit() {
  form.transform(d => ({
    ...d,
    lines: d.lines.map(({ _accountDisplay, ...rest }) => rest),
  })).put(route('accounting.journal-entries.update', props.entry.id));
}

function submitDraft() {
  form.save_as_draft = true;
  form.transform(d => ({
    ...d,
    lines: d.lines.map(({ _accountDisplay, ...rest }) => rest),
  })).post(route('accounting.journal-entries.store'));
}

function submitPost() {
  form.save_as_draft = false;
  form.transform(d => ({
    ...d,
    lines: d.lines.map(({ _accountDisplay, ...rest }) => rest),
  })).post(route('accounting.journal-entries.store'));
}
</script>
