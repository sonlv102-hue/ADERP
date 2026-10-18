<template>
  <div v-if="stats?.length" class="rounded-lg border border-amber-300 bg-amber-50 p-3">
    <p class="text-sm font-medium text-amber-800">⚠ Chưa có giá trị nguồn (amount = NULL)</p>
    <p class="text-xs text-amber-700 mt-0.5">
      Các giao dịch dưới đây có số lượng ảnh hưởng tồn kho nhưng chưa có giá trị tiền trong dữ liệu gốc
      (không phải giá trị = 0đ thực sự) — không suy diễn số tiền thiếu, cần xử lý bằng task riêng nếu cần.
    </p>
    <table class="w-full text-xs mt-2">
      <thead><tr class="text-amber-700"><th class="text-left py-1">Loại nghiệp vụ</th><th class="text-right py-1">Số dòng</th><th class="text-right py-1">SL liên quan</th></tr></thead>
      <tbody>
        <tr v-for="s in stats" :key="s.source_type" class="border-t border-amber-200">
          <td class="py-1">{{ s.label }}</td>
          <td class="py-1 text-right">{{ s.count }}</td>
          <td class="py-1 text-right">{{ fmtQty(s.qty) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
  <p v-else class="text-xs text-gray-400">Không có giao dịch thiếu giá trị nguồn trong phạm vi này.</p>
</template>

<script setup>
defineProps({ stats: { type: Array, default: () => [] } });
function fmtQty(n) { return Number(n ?? 0).toLocaleString('vi-VN'); }
</script>
