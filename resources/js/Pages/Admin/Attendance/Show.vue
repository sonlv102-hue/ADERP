<template>
  <AppLayout :title="`Chấm công ${formatPeriod(sheet.period)}`">
    <div class="py-4 px-2">

      <!-- Header -->
      <div class="max-w-full mx-auto mb-4 px-2 flex flex-wrap items-start gap-4">
        <div class="flex-1">
          <div class="flex items-center gap-2 text-sm text-gray-500 mb-1">
            <Link :href="route('admin.attendance.index')" class="hover:text-gray-700">Chấm công</Link>
            <span>/</span>
            <span class="text-gray-700 font-medium">{{ sheet.code }}</span>
          </div>
          <div class="flex items-center gap-3 flex-wrap">
            <h1 class="text-xl font-bold text-gray-900">BẢNG CHẤM CÔNG — {{ formatPeriod(sheet.period).toUpperCase() }}</h1>
            <StatusBadge :color="sheet.status_color">{{ sheet.status_label }}</StatusBadge>
          </div>
          <p class="text-xs text-gray-400 mt-1">{{ sheet.creator }} lập ngày {{ sheet.created_at }}</p>
        </div>
        <div class="flex items-center gap-2 print:hidden flex-wrap">
          <a :href="route('admin.attendance.export-excel', sheet.id)"
            class="inline-flex items-center gap-1 px-3 py-1.5 border border-gray-300 text-sm rounded-lg hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            Xuất Excel
          </a>
          <button @click="printSheet"
            class="inline-flex items-center gap-1 px-3 py-1.5 border border-gray-300 text-sm rounded-lg hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            In bảng
          </button>
          <button v-if="sheet.status === 'draft'" @click="lockSheet"
            class="inline-flex items-center gap-1 px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            Khóa công
          </button>
          <button v-if="sheet.status === 'locked'" @click="unlockSheet"
            class="inline-flex items-center gap-1 px-3 py-1.5 border border-orange-400 text-orange-600 text-sm rounded-lg hover:bg-orange-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 018 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/>
            </svg>
            Mở lại
          </button>
        </div>
      </div>

      <!-- Flash -->
      <div v-if="$page.props.flash?.success" class="mx-2 mb-3 bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.success }}</div>
      <div v-if="$page.props.flash?.error"   class="mx-2 mb-3 bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">{{ $page.props.flash.error }}</div>

      <!-- Legend (screen only) -->
      <div class="mx-2 mb-3 print:hidden bg-blue-50 border border-blue-200 rounded-lg px-4 py-2">
        <p class="text-xs font-semibold text-blue-700 mb-1.5">Ký hiệu chấm công:</p>
        <div class="flex flex-wrap gap-x-5 gap-y-1">
          <span v-for="(desc, sym) in SYMBOLS" :key="sym" class="text-xs flex items-center gap-1">
            <strong :class="symTextClass(sym)" class="font-bold text-sm w-5 text-center">{{ sym }}</strong>
            <span class="text-gray-600">{{ desc }}</span>
          </span>
        </div>
        <p v-if="sheet.status === 'draft'" class="text-xs text-blue-600 mt-1.5 italic">
          Nhấp vào ô ngày và gõ ký hiệu, nhấn <kbd class="bg-white border px-1 rounded">Enter</kbd> hoặc <kbd class="bg-white border px-1 rounded">Tab</kbd> để chuyển ô — nhấn <strong>Lưu</strong> để ghi dữ liệu.
        </p>
      </div>

      <!-- Attendance Grid -->
      <div class="overflow-x-auto mx-2 rounded-xl border border-gray-200 shadow-sm">
        <table class="text-xs border-collapse" style="min-width: 900px; width: 100%">
          <thead>
            <!-- Day numbers row -->
            <tr class="bg-gray-700 text-white">
              <th class="px-2 py-2 text-center w-8 border border-gray-600">STT</th>
              <th class="px-2 py-2 text-left w-[60px] border border-gray-600">Mã NV</th>
              <th class="px-2 py-2 text-left min-w-[120px] border border-gray-600">Họ và tên</th>
              <th class="px-2 py-2 text-left min-w-[70px] border border-gray-600">Chức vụ</th>
              <th v-for="d in daysInMonth" :key="d"
                :class="['w-7 text-center border border-gray-600 select-none', isSunday(d) ? 'bg-red-600' : '']">
                {{ d }}
              </th>
              <th class="px-1 py-2 text-center w-10 border border-gray-600">Công</th>
              <th class="px-1 py-2 text-center w-12 border border-gray-600 text-[9px] leading-tight">NghỉHL</th>
              <th class="px-1 py-2 text-center w-12 border border-gray-600 text-[9px] leading-tight">NghỉKL</th>
              <th class="px-1 py-2 text-center w-7 border border-gray-600">OT</th>
              <th class="px-1 py-2 text-center w-10 border border-gray-600">Tổng</th>
              <th v-if="sheet.status === 'draft'" class="px-2 py-2 text-center w-14 border border-gray-600 print:hidden">Lưu</th>
            </tr>
            <!-- Day-of-week row -->
            <tr class="bg-gray-100 text-gray-500">
              <th colspan="4" class="border border-gray-200"></th>
              <th v-for="d in daysInMonth" :key="d"
                :class="['text-center border border-gray-200 py-0.5 text-[9px]', isSunday(d) ? 'bg-red-100 text-red-600 font-bold' : '']">
                {{ DOW_SHORT[dayHeaders[d]] }}
              </th>
              <th :colspan="sheet.status === 'draft' ? 6 : 5" class="border border-gray-200"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(rec, idx) in localRecords" :key="rec.id"
              :class="['border-b border-gray-100', dirtyRows.has(rec.id) ? 'bg-yellow-50' : (idx % 2 === 0 ? 'bg-white' : 'bg-gray-50/40')]">
              <td class="px-2 py-1 text-center border border-gray-100 text-gray-400">{{ idx + 1 }}</td>
              <!-- Employee code — click to view detail -->
              <td class="px-2 py-1 border border-gray-100">
                <button @click="openDetail(rec)"
                  class="font-mono text-primary-600 hover:underline text-xs">
                  {{ rec.employee_code }}
                </button>
              </td>
              <td class="px-2 py-1 font-medium border border-gray-100">
                <button @click="openDetail(rec)" class="hover:text-primary-600 text-left">
                  {{ rec.employee_name }}
                </button>
              </td>
              <td class="px-2 py-1 text-gray-500 border border-gray-100 text-[10px]">{{ rec.position }}</td>

              <!-- Day cells -->
              <td v-for="d in daysInMonth" :key="d"
                :class="['text-center border border-gray-100 p-0', isSunday(d) ? 'bg-red-50' : '']">
                <input
                  v-if="sheet.status === 'draft'"
                  :value="rec.days[d] ?? ''"
                  @input="onInput(rec, d, $event.target.value)"
                  @keydown.enter.prevent="$event.target.nextElementSibling?.focus(); $event.target.blur()"
                  @keydown.tab="$event.target.blur()"
                  maxlength="3"
                  :class="['w-full text-center py-1 border-0 bg-transparent focus:outline-none focus:bg-primary-50 focus:ring-1 focus:ring-inset focus:ring-primary-400 uppercase rounded-sm', symTextClass(rec.days[d])]"
                  style="min-width: 26px"
                />
                <span v-else :class="['block text-center py-1', symTextClass(rec.days[d])]">
                  {{ rec.days[d] ?? '' }}
                </span>
              </td>

              <!-- Summary -->
              <td class="px-1 py-1 text-center font-semibold border border-gray-100">{{ rec.cong }}</td>
              <td class="px-1 py-1 text-center border border-gray-100">{{ rec.nghi_huong_luong || '' }}</td>
              <td class="px-1 py-1 text-center border border-gray-100">{{ rec.nghi_khong_luong || '' }}</td>
              <td class="px-1 py-1 text-center border border-gray-100">{{ rec.ot || '' }}</td>
              <td class="px-1 py-1 text-center font-semibold border border-gray-100">{{ rec.tong }}</td>

              <!-- Save button -->
              <td v-if="sheet.status === 'draft'" class="px-1 py-1 text-center border border-gray-100 print:hidden">
                <button v-if="dirtyRows.has(rec.id)"
                  @click="saveRow(rec)"
                  :disabled="savingRows.has(rec.id)"
                  class="px-2 py-0.5 text-[10px] bg-primary-600 text-white rounded hover:bg-primary-700 disabled:opacity-50 whitespace-nowrap">
                  {{ savingRows.has(rec.id) ? '...' : 'Lưu' }}
                </button>
                <svg v-else-if="savedRows.has(rec.id)" class="w-4 h-4 text-green-500 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
              </td>
            </tr>

            <!-- Summary totals row -->
            <tr class="bg-gray-100 font-semibold border-t-2 border-gray-300">
              <td colspan="4" class="px-3 py-2 text-right text-gray-700 border border-gray-200 text-xs">TỔNG CỘNG</td>
              <td v-for="d in daysInMonth" :key="d" class="border border-gray-200"></td>
              <td class="px-1 py-2 text-center border border-gray-200">{{ totals.cong }}</td>
              <td class="px-1 py-2 text-center border border-gray-200">{{ totals.nghi_huong_luong || '' }}</td>
              <td class="px-1 py-2 text-center border border-gray-200">{{ totals.nghi_khong_luong || '' }}</td>
              <td class="px-1 py-2 text-center border border-gray-200">{{ totals.ot || '' }}</td>
              <td class="px-1 py-2 text-center border border-gray-200">{{ totals.tong }}</td>
              <td v-if="sheet.status === 'draft'" class="border border-gray-200 print:hidden"></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Legend (print) -->
      <div class="mt-4 mx-2 print:mt-6 hidden print:block">
        <p class="text-xs font-semibold text-gray-600 mb-1">Ký hiệu:</p>
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
          <span v-for="(desc, sym) in SYMBOLS" :key="sym">
            <strong>{{ sym }}</strong>: {{ desc }}
          </span>
        </div>
      </div>

      <!-- Signature row (print) — shared component, see docs/REPORTING_STANDARDS.md -->
      <div class="hidden print:block mt-8 px-4">
        <ReportSignatureSection
          :signing-place="company?.report_signing_place"
          :signing-date="printDate"
          :signers="[
            { title: 'Người lập bảng', instruction: '(Ký, ghi rõ họ tên)', name: sheet.creator },
            { title: 'Phòng Kế Toán',  instruction: '(Ký, ghi rõ họ tên)' },
            { title: 'Giám Đốc',       instruction: '(Ký, ghi rõ họ tên, đóng dấu)' },
          ]"
        />
      </div>
    </div>

    <!-- Employee Detail Modal -->
    <Teleport to="body">
      <div v-if="detailRec" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
          <!-- Modal header -->
          <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex items-start justify-between rounded-t-xl">
            <div>
              <p class="text-xs text-gray-400 mb-0.5">{{ sheet.code }} — {{ formatPeriod(sheet.period) }}</p>
              <h2 class="text-lg font-bold text-gray-900">{{ detailRec.employee_name }}</h2>
              <p class="text-sm text-gray-500">{{ detailRec.employee_code }} | {{ detailRec.position }} | {{ detailRec.department }}</p>
            </div>
            <button @click="detailRec = null" class="text-gray-400 hover:text-gray-600 p-1">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>

          <div class="p-6">
            <!-- Summary cards -->
            <div class="grid grid-cols-5 gap-3 mb-6">
              <div class="bg-green-50 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-green-700">{{ detailRec.cong }}</p>
                <p class="text-xs text-green-600">Ngày công</p>
              </div>
              <div class="bg-blue-50 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-blue-700">{{ detailRec.nghi_huong_luong }}</p>
                <p class="text-xs text-blue-600">Nghỉ hưởng lương</p>
              </div>
              <div class="bg-red-50 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-red-700">{{ detailRec.nghi_khong_luong }}</p>
                <p class="text-xs text-red-600">Nghỉ không lương</p>
              </div>
              <div class="bg-orange-50 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-orange-700">{{ detailRec.ot }}</p>
                <p class="text-xs text-orange-600">Tăng ca</p>
              </div>
              <div class="bg-gray-50 rounded-lg p-3 text-center">
                <p class="text-2xl font-bold text-gray-700">{{ detailRec.tong }}</p>
                <p class="text-xs text-gray-600">Tổng ngày</p>
              </div>
            </div>

            <!-- Calendar -->
            <div class="border border-gray-200 rounded-lg overflow-hidden">
              <div class="grid grid-cols-7 bg-gray-700 text-white text-xs text-center">
                <div v-for="dow in ['T2','T3','T4','T5','T6','T7','CN']" :key="dow"
                  :class="['py-2 font-semibold', dow === 'CN' ? 'bg-red-700' : '']">
                  {{ dow }}
                </div>
              </div>
              <!-- Weeks -->
              <div v-for="(week, wi) in calendarWeeks" :key="wi" class="grid grid-cols-7 border-t border-gray-100">
                <div v-for="(cell, ci) in week" :key="ci"
                  :class="['py-3 text-center border-r border-gray-100 last:border-r-0 relative',
                    cell ? '' : 'bg-gray-50',
                    cell && isSundayD(cell.day) ? 'bg-red-50' : '']">
                  <template v-if="cell">
                    <span class="absolute top-1 left-1.5 text-[9px] text-gray-400">{{ cell.day }}</span>
                    <span :class="['text-sm font-bold mt-1 block', symTextClass(cell.symbol)]">
                      {{ cell.symbol || '' }}
                    </span>
                  </template>
                </div>
              </div>
            </div>

            <!-- Per-symbol breakdown -->
            <div class="mt-4 grid grid-cols-3 gap-2">
              <div v-for="(count, sym) in detailSymbolBreakdown" :key="sym"
                class="flex items-center gap-2 bg-gray-50 rounded px-3 py-2">
                <span :class="['font-bold text-sm w-6 text-center', symTextClass(sym)]">{{ sym }}</span>
                <span class="text-xs text-gray-600">{{ SYMBOLS[sym] || sym }}</span>
                <span class="ml-auto font-semibold text-gray-800">{{ count }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

  </AppLayout>
</template>

<script setup>
import { ref, computed, reactive } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import ReportSignatureSection from '@/Components/Shared/ReportSignatureSection.vue';

const company = computed(() => usePage().props.company);
const printDate = new Date();

const props = defineProps({
  sheet:       Object,
  records:     Array,
  daysInMonth: Number,
  dayHeaders:  Object, // {1: dow, ...}  dow: 1=Mon..7=Sun
});

const SYMBOLS = {
  X:  'Đi làm',
  P:  'Nghỉ phép',
  KP: 'Nghỉ không phép',
  L:  'Nghỉ lễ',
  CT: 'Công tác',
  OT: 'Tăng ca',
  NB: 'Nghỉ bù',
  Ô:  'Ốm',
  TS: 'Thai sản',
};
const DOW_SHORT = { 1:'T2', 2:'T3', 3:'T4', 4:'T5', 5:'T6', 6:'T7', 7:'CN' };

// ── Local mutable state ──────────────────────────────────────────────────
const localRecords = ref(
  props.records.map(r => ({
    ...r,
    days: Object.fromEntries(
      Array.from({ length: props.daysInMonth }, (_, i) => [i + 1, r.days?.[i + 1] ?? null])
    ),
  }))
);

const dirtyRows  = reactive(new Set());
const savingRows = reactive(new Set());
const savedRows  = reactive(new Set());

// ── Helpers ──────────────────────────────────────────────────────────────
function isSunday(d) {
  return Number(props.dayHeaders[d]) === 7;
}
function isSundayD(d) { return isSunday(d); }

function formatPeriod(period) {
  const [y, m] = period.split('-');
  return `Tháng ${parseInt(m)}/${y}`;
}

function symTextClass(symbol) {
  if (!symbol) return 'text-gray-300';
  const s = String(symbol).toUpperCase();
  if (s === 'X')              return 'text-green-700 font-semibold';
  if (s === 'P')              return 'text-blue-600';
  if (s === 'KP')             return 'text-red-600 font-bold';
  if (s === 'L')              return 'text-purple-600 font-bold';
  if (s === 'CT')             return 'text-teal-600';
  if (s === 'OT')             return 'text-orange-600 font-bold';
  if (s === 'NB')             return 'text-indigo-600';
  if (['Ô','O'].includes(s))  return 'text-yellow-600';
  if (s === 'TS')             return 'text-pink-600';
  return 'text-gray-600';
}

// ── Attendance input ──────────────────────────────────────────────────────
function onInput(rec, day, rawValue) {
  const symbol = rawValue.toUpperCase().trim();
  rec.days[day] = symbol || null;
  recalcLocal(rec);
  dirtyRows.add(rec.id);
  savedRows.delete(rec.id);
}

function recalcLocal(rec) {
  let cong = 0, huong = 0, khong = 0, ot = 0, tong = 0;
  for (const sym of Object.values(rec.days)) {
    if (!sym) continue;
    const s = String(sym).toUpperCase();
    tong++;
    if (['X', 'CT'].includes(s))                    cong++;
    else if (['P', 'Ô', 'O', 'NB', 'TS', 'L'].includes(s)) huong++;
    else if (s === 'KP')                             khong++;
    else if (s === 'OT')                             ot++;
  }
  rec.cong              = cong;
  rec.nghi_huong_luong  = huong;
  rec.nghi_khong_luong  = khong;
  rec.ot                = ot;
  rec.tong              = tong;
}

function saveRow(rec) {
  savingRows.add(rec.id);
  router.put(
    route('admin.attendance.records.update', { attendance: props.sheet.id, record: rec.id }),
    { days: rec.days },
    {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        dirtyRows.delete(rec.id);
        savedRows.add(rec.id);
        savingRows.delete(rec.id);
        // Clear green checkmark after 3s
        setTimeout(() => savedRows.delete(rec.id), 3000);
      },
      onError: () => {
        savingRows.delete(rec.id);
      },
    }
  );
}

// ── Totals ────────────────────────────────────────────────────────────────
const totals = computed(() => ({
  cong:             localRecords.value.reduce((s, r) => s + r.cong, 0),
  nghi_huong_luong: localRecords.value.reduce((s, r) => s + r.nghi_huong_luong, 0),
  nghi_khong_luong: localRecords.value.reduce((s, r) => s + r.nghi_khong_luong, 0),
  ot:               localRecords.value.reduce((s, r) => s + r.ot, 0),
  tong:             localRecords.value.reduce((s, r) => s + r.tong, 0),
}));

// ── Lock / Unlock ──────────────────────────────────────────────────────────
function lockSheet() {
  if (!confirm(`Khóa bảng chấm công ${props.sheet.code}?\nSau khi khóa sẽ không chỉnh sửa được.`)) return;
  router.post(route('admin.attendance.lock', props.sheet.id));
}
function unlockSheet() {
  if (!confirm(`Mở lại bảng chấm công ${props.sheet.code}?`)) return;
  router.post(route('admin.attendance.unlock', props.sheet.id));
}
function printSheet() { window.print(); }

// ── Employee Detail Modal ──────────────────────────────────────────────────
const detailRec = ref(null);

function openDetail(rec) {
  detailRec.value = localRecords.value.find(r => r.id === rec.id) ?? rec;
}

// Calendar weeks for detail modal
const calendarWeeks = computed(() => {
  if (!detailRec.value) return [];
  const rec = detailRec.value;
  const [year, month] = props.sheet.period.split('-').map(Number);

  // Find first day of month (1=Mon..7=Sun)
  const firstDow = Number(props.dayHeaders[1]); // dow of day 1
  const paddingBefore = firstDow - 1; // Mon=0, Tue=1, ...

  const cells = [];
  for (let i = 0; i < paddingBefore; i++) cells.push(null);
  for (let d = 1; d <= props.daysInMonth; d++) {
    cells.push({ day: d, symbol: rec.days[d] ?? null });
  }
  // Pad to complete last week
  while (cells.length % 7 !== 0) cells.push(null);

  const weeks = [];
  for (let i = 0; i < cells.length; i += 7) {
    weeks.push(cells.slice(i, i + 7));
  }
  return weeks;
});

const detailSymbolBreakdown = computed(() => {
  if (!detailRec.value) return {};
  const counts = {};
  for (const sym of Object.values(detailRec.value.days)) {
    if (!sym) continue;
    const s = String(sym).toUpperCase();
    counts[s] = (counts[s] || 0) + 1;
  }
  return counts;
});
</script>

<style>
@media print {
  nav, header, aside, .print\:hidden { display: none !important; }
  body { font-size: 10px; }
  table { font-size: 9px; }
  th, td { padding: 2px 3px !important; }
}
</style>
