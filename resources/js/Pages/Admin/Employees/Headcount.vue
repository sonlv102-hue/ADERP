<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center gap-3">
        <Link :href="route('admin.employees.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Báo cáo biến động nhân sự</h1>
      </div>

      <div class="flex gap-3 flex-wrap items-end">
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Từ ngày</label>
          <input v-model="from" type="date" class="erp-input w-full sm:w-44" />
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Đến ngày</label>
          <input v-model="to" type="date" class="erp-input w-full sm:w-44" />
        </div>
        <button class="erp-btn-primary" @click="apply">Xem</button>
      </div>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
          <p class="text-xs text-gray-500">Nhân viên đầu kỳ</p>
          <p class="text-2xl font-bold text-gray-900 mt-1">{{ summary.opening }}</p>
        </div>
        <div class="rounded-xl border border-green-200 bg-green-50 p-4">
          <p class="text-xs text-green-600">Tăng trong kỳ</p>
          <p class="text-2xl font-bold text-green-700 mt-1">+{{ summary.increase }}</p>
        </div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4">
          <p class="text-xs text-red-600">Giảm trong kỳ</p>
          <p class="text-2xl font-bold text-red-700 mt-1">−{{ summary.decrease }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
          <p class="text-xs text-gray-500">Nhân viên cuối kỳ</p>
          <p class="text-2xl font-bold text-gray-900 mt-1">{{ summary.closing }}</p>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <DrillTable title="Tăng trong kỳ (tuyển mới)" :rows="increaseList" date-label="Ngày vào làm" />
        <DrillTable title="Giảm trong kỳ (thôi việc)" :rows="decreaseList" date-label="Ngày thôi việc" show-reason />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, h } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  filters: Object,
  summary: Object,
  increase_list: Array,
  decrease_list: Array,
});

const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
const increaseList = props.increase_list ?? [];
const decreaseList = props.decrease_list ?? [];

function apply() {
  router.get(route('admin.employees.headcount'), { from: from.value, to: to.value }, { preserveState: true, replace: true });
}

const DrillTable = {
  props: ['title', 'rows', 'dateLabel', 'showReason'],
  setup(p) {
    return () => h('div', { class: 'bg-white rounded-xl border border-gray-200 overflow-x-auto' }, [
      h('p', { class: 'px-5 py-3 font-semibold text-gray-700 border-b border-gray-100 text-sm' }, p.title),
      h('table', { class: 'min-w-full text-sm' }, [
        h('thead', { class: 'bg-gray-50 border-b border-gray-200' }, h('tr', [
          h('th', { class: 'text-left px-4 py-2 font-semibold text-gray-600' }, 'Mã'),
          h('th', { class: 'text-left px-4 py-2 font-semibold text-gray-600' }, 'Họ tên'),
          h('th', { class: 'text-left px-4 py-2 font-semibold text-gray-600' }, 'Phòng ban'),
          h('th', { class: 'text-left px-4 py-2 font-semibold text-gray-600' }, p.dateLabel),
          p.showReason ? h('th', { class: 'text-left px-4 py-2 font-semibold text-gray-600' }, 'Lý do') : null,
        ])),
        h('tbody', { class: 'divide-y divide-gray-100' },
          p.rows.length
            ? p.rows.map((r) => h('tr', { class: 'hover:bg-gray-50' }, [
                h('td', { class: 'px-4 py-2 font-mono text-primary-700' }, r.code),
                h('td', { class: 'px-4 py-2 text-gray-900' }, r.name),
                h('td', { class: 'px-4 py-2 text-gray-600' }, r.department ?? '—'),
                h('td', { class: 'px-4 py-2 text-gray-600' }, r.date ?? '—'),
                p.showReason ? h('td', { class: 'px-4 py-2 text-gray-600' }, r.reason ?? '—') : null,
              ]))
            : h('tr', h('td', { colspan: p.showReason ? 5 : 4, class: 'px-4 py-8 text-center text-gray-400' }, 'Không có dữ liệu')),
        ),
      ]),
    ]);
  },
};
</script>
