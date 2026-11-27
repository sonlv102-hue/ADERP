<template>
  <DataTableWrapper class="mt-3">
    <table class="min-w-full text-xs">
      <thead class="bg-gray-50">
        <tr>
          <th v-for="h in ['Quy tắc', 'Nhóm', 'Phân loại mặc định', 'Mức độ', 'Điều kiện phát hiện', 'Công thức đối chiếu', 'Nguồn dữ liệu', 'Phạm vi', 'Trạng thái']" :key="h"
            class="text-left px-2 py-1.5 font-semibold text-gray-600 whitespace-nowrap">{{ h }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <tr v-for="r in rules" :key="r.code">
          <td class="px-2 py-1 font-mono whitespace-nowrap">{{ r.code }}<div class="font-sans text-gray-500">{{ RULE_LABELS[r.code] }}</div></td>
          <td class="px-2 py-1">{{ GROUPS[r.group] ?? r.group }}</td>
          <td class="px-2 py-1">{{ CATEGORY_LABELS[r.category] }}</td>
          <td class="px-2 py-1">{{ SEVERITY[r.severity]?.[0] }}</td>
          <td class="px-2 py-1 min-w-[200px]">{{ r.condition }}</td>
          <td class="px-2 py-1 font-mono min-w-[220px]">{{ r.formula }}</td>
          <td class="px-2 py-1 min-w-[160px]">{{ r.sources }}</td>
          <td class="px-2 py-1 whitespace-nowrap">{{ r.scope }}</td>
          <td class="px-2 py-1 whitespace-nowrap">{{ r.implemented ? 'Đang áp dụng' : 'Chưa triển khai' }}</td>
        </tr>
      </tbody>
    </table>
    <p class="text-xs text-gray-500 mt-2">Phân loại bổ sung: LEGACY_DATA (dữ liệu trước AVCO/khởi tạo lại — gắn cờ, không tính là lỗi), EXPECTED_DIFFERENCE (làm tròn ≤ 10đ),
      POLICY_DECISION (chủ đề chính sách, không tính vào số sản phẩm sai lệch). Ngưỡng: số lượng 0,0005; giá trị 10đ.</p>
  </DataTableWrapper>
</template>

<script setup>
import DataTableWrapper from '@/Components/Shared/DataTableWrapper.vue'
import { RULE_LABELS, CATEGORY_LABELS, SEVERITY } from '../labels'

defineProps({ rules: Array })
const GROUPS = { inventory: 'Tồn kho', document: 'Chứng từ', accounting: 'Kế toán', classification: 'Phân loại' }
</script>
