<template>
  <div class="border border-amber-200 bg-amber-50/50 rounded-lg p-3 space-y-2">
    <h3 class="font-semibold text-gray-900">Phương án xử lý {{ caseInfo?.title ? '— ' + caseInfo.title : '' }}</h3>
    <p v-if="caseInfo?.summary" class="text-gray-700">{{ caseInfo.summary }}</p>
    <p v-if="caseInfo?.source" class="text-xs text-gray-500">Nguồn: {{ caseInfo.source }}</p>
    <DataTableWrapper v-if="allOptions.length">
      <table class="min-w-full text-xs">
        <thead class="bg-white"><tr>
          <th class="text-left px-2 py-1 font-semibold text-gray-600">Phương án</th>
          <th class="text-left px-2 py-1 font-semibold text-gray-600">Mô tả / ảnh hưởng</th>
        </tr></thead>
        <tbody class="divide-y divide-amber-100">
          <tr v-for="(o, k) in allOptions" :key="k">
            <td class="px-2 py-1 font-medium whitespace-nowrap align-top">{{ o.name }}</td>
            <td class="px-2 py-1 align-top">{{ o.description ?? '' }} <span v-if="o.impact" class="text-gray-600">{{ typeof o.impact === 'string' ? o.impact : impactText(o.impact) }}</span></td>
          </tr>
        </tbody>
      </table>
    </DataTableWrapper>
    <div v-if="caseInfo?.to_confirm?.length">
      <p class="font-medium text-gray-800">Cần xác nhận:</p>
      <ul class="list-disc ml-5 text-gray-700"><li v-for="(q, k) in caseInfo.to_confirm" :key="k">{{ q }}</li></ul>
    </div>
    <div v-if="resolution && Object.keys(resolution).length" class="text-xs text-gray-700 border-t border-amber-200 pt-2">
      <b>Kết quả của kế toán:</b>
      <span v-for="(v, k) in shownResolution" :key="k" class="mr-3">{{ RES_LABELS[k] ?? k }}: {{ v }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import DataTableWrapper from '@/Components/Shared/DataTableWrapper.vue'

const props = defineProps({ caseInfo: Object, options: Array, resolution: Object })
const RES_LABELS = { check_result: 'Kết quả', cause: 'Nguyên nhân', chosen_option: 'Phương án chọn', adjustment_ref: 'Chứng từ/JE điều chỉnh', business_note: 'Ghi chú' }
const allOptions = computed(() => [...(props.caseInfo?.options ?? []), ...(props.options ?? [])])
const shownResolution = computed(() => Object.fromEntries(Object.entries(props.resolution ?? {}).filter(([k]) => RES_LABELS[k])))
const impactText = (o) => Object.entries(o).map(([k, v]) => `${k}: ${v}`).join(' · ')
</script>
