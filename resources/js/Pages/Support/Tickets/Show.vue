<template>
  <AppLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-start justify-between">
        <div class="flex items-center gap-3">
          <Link :href="route('support.tickets.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Link>
          <div>
            <div class="flex items-center gap-3">
              <h1 class="text-2xl font-bold text-gray-900">{{ ticket.title }}</h1>
              <StatusBadge :color="ticket.status_color">{{ ticket.status_label }}</StatusBadge>
              <StatusBadge :color="ticket.priority_color">{{ ticket.priority_label }}</StatusBadge>
            </div>
            <p class="text-sm text-gray-500 mt-0.5">{{ ticket.code }} · {{ ticket.customer.name }}</p>
          </div>
        </div>
        <div class="flex gap-2">
          <Link v-if="can('tickets.create')" :href="route('support.tickets.edit', ticket.id)"
            class="border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
            Sửa
          </Link>
          <template v-for="t in ticket.allowed_transitions" :key="t.value">
            <button @click="doTransition(t.value)"
              :class="['px-4 py-2 rounded-lg text-sm font-medium', t.value === 'open' ? 'border border-gray-300 text-gray-700 hover:bg-gray-50' : 'bg-primary-600 hover:bg-primary-700 text-white']">
              {{ t.label }}
            </button>
          </template>
        </div>
      </div>

      <div class="grid grid-cols-3 gap-5">
        <!-- Left: detail + log -->
        <div class="col-span-2 space-y-5">
          <!-- Description -->
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="font-semibold text-gray-800 mb-3">Mô tả</h2>
            <p class="text-sm text-gray-600 whitespace-pre-wrap">{{ ticket.description || '(Không có mô tả)' }}</p>
          </div>

          <!-- Timeline / Logs -->
          <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="font-semibold text-gray-800 mb-4">Lịch sử xử lý</h2>

            <div class="space-y-4">
              <div v-for="log in ticket.logs" :key="log.id" class="flex gap-3">
                <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-500 shrink-0">
                  {{ log.user.charAt(0) }}
                </div>
                <div class="flex-1">
                  <div class="text-xs text-gray-400 mb-1">{{ log.user }} · {{ log.created_at }}</div>
                  <div v-if="log.action === 'note'" class="text-sm text-gray-700 bg-gray-50 rounded-lg px-3 py-2">
                    {{ log.note }}
                  </div>
                  <div v-else-if="log.action === 'status_change'" class="text-sm text-gray-600">
                    Chuyển trạng thái: <span class="font-medium">{{ log.old_value }}</span>
                    → <span class="font-medium text-primary-600">{{ log.new_value }}</span>
                  </div>
                  <div v-else-if="log.action === 'assign'" class="text-sm text-gray-600">
                    Phân công xử lý cho user #{{ log.new_value || '(bỏ phân công)' }}
                  </div>
                  <div v-else class="text-sm text-gray-500 italic">{{ log.action }}</div>
                </div>
              </div>
              <div v-if="!ticket.logs.length" class="text-sm text-gray-400 text-center py-4">Chưa có lịch sử</div>
            </div>

            <!-- Add note -->
            <div v-if="can('tickets.create')" class="mt-4 pt-4 border-t border-gray-100">
              <textarea v-model="noteText" rows="3" placeholder="Thêm ghi chú xử lý..."
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
              <div class="flex justify-end mt-2">
                <button @click="addNote" :disabled="!noteText.trim()"
                  class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50">
                  Ghi nhận
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Right: meta info -->
        <div class="space-y-4">
          <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-4 text-sm">
            <h2 class="font-semibold text-gray-800">Thông tin</h2>
            <div>
              <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Danh mục</p>
              <p class="text-gray-800">{{ categoryLabel }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Người phụ trách</p>
              <p class="text-gray-800">{{ ticket.assignee?.name ?? '—' }}</p>
            </div>
            <div>
              <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Hạn xử lý</p>
              <p class="text-gray-800">{{ ticket.due_date ?? '—' }}</p>
            </div>
            <div v-if="ticket.order">
              <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Đơn hàng</p>
              <Link :href="route('sales.orders.show', ticket.order.id)" class="text-primary-600 hover:underline">
                {{ ticket.order.code }}
              </Link>
            </div>
            <div v-if="ticket.contract">
              <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Hợp đồng</p>
              <Link :href="route('sales.contracts.show', ticket.contract.id)" class="text-primary-600 hover:underline">
                {{ ticket.contract.code }}
              </Link>
            </div>
            <div>
              <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Tạo bởi</p>
              <p class="text-gray-800">{{ ticket.creator }} · {{ ticket.created_at }}</p>
            </div>
            <div v-if="ticket.resolved_at">
              <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Giải quyết lúc</p>
              <p class="text-gray-800">{{ ticket.resolved_at }}</p>
            </div>
            <div v-if="ticket.closed_at">
              <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Đóng lúc</p>
              <p class="text-gray-800">{{ ticket.closed_at }}</p>
            </div>
          </div>

          <!-- Assign form -->
          <div v-if="can('tickets.assign')" class="bg-white rounded-xl border border-gray-200 p-5 space-y-3">
            <h2 class="font-semibold text-gray-800 text-sm">Phân công</h2>
            <select v-model="assignedTo" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
              <option value="">-- Bỏ phân công --</option>
              <option v-for="u in allUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
            <button @click="doAssign"
              class="w-full bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium">
              Phân công
            </button>
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
  ticket: Object,
  allUsers: Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;

const noteText  = ref('');
const assignedTo = ref(props.ticket?.assignee?.id ?? '');

const categoryMap = {
  hardware: 'Phần cứng',
  software: 'Phần mềm',
  network:  'Mạng',
  other:    'Khác',
};
const categoryLabel = computed(() => categoryMap[props.ticket.category] ?? props.ticket.category ?? '—');

function doTransition(status) {
  router.post(route('support.tickets.transition', props.ticket.id), { status });
}

function doAssign() {
  router.post(route('support.tickets.assign', props.ticket.id), {
    assigned_to: assignedTo.value || null,
  });
}

function addNote() {
  if (!noteText.value.trim()) return;
  router.post(route('support.tickets.note', props.ticket.id), { note: noteText.value }, {
    onSuccess: () => { noteText.value = ''; },
  });
}
</script>
