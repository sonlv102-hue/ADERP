<template>
  <div class="report-signature-section break-inside-avoid print:break-inside-avoid">
    <div v-if="showSigningDate && signingDate" class="report-signing-date">
      {{ signingPlace ? signingPlace + ', ' : '' }}ngày {{ dateParts.d }} tháng {{ dateParts.m }} năm {{ dateParts.y }}
    </div>

    <div class="report-signature-row" :style="{ gridTemplateColumns: `repeat(${signers.length}, 1fr)` }">
      <div v-for="(signer, i) in signers" :key="i" class="report-signature-col">
        <div class="report-signature-title">{{ signer.title }}</div>
        <div class="report-signature-instruction">{{ signer.instruction || '' }}</div>
        <div class="report-signature-space">
          <img v-if="signer.signature_image" :src="signer.signature_image" class="report-signature-image" />
        </div>
        <div class="report-signature-name">{{ signer.name || '' }}</div>
        <div v-if="signer.position" class="report-signature-position">{{ signer.position }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  signingPlace:    { type: String, default: '' },
  signingDate:     { type: [String, Date], default: null }, // 'YYYY-MM-DD' or Date
  signers:         { type: Array, required: true }, // [{ title, instruction, name, position, signature_image }]
  showSigningDate: { type: Boolean, default: true },
});

// Note: a 'YYYY-MM-DD' string parses as UTC midnight; getDate()/getMonth() read
// it back in the browser's local timezone. Safe for Asia/Ho_Chi_Minh (UTC+7,
// ahead of UTC) but could shift a day back in timezones behind UTC — prefer
// passing a Date object when the caller already has one (see Attendance/Show.vue).
const dateParts = computed(() => {
  if (!props.signingDate) return { d: '', m: '', y: '' };
  const d = props.signingDate instanceof Date ? props.signingDate : new Date(props.signingDate);
  return {
    d: String(d.getDate()).padStart(2, '0'),
    m: String(d.getMonth() + 1).padStart(2, '0'),
    y: d.getFullYear(),
  };
});
</script>

<style scoped>
.report-signature-section {
  margin-top: 18px;
  break-inside: avoid;
  page-break-inside: avoid;
}
.report-signing-date {
  margin-bottom: 10px;
  text-align: right;
  font-size: 11px;
  font-style: italic;
  white-space: nowrap;
}
.report-signature-row {
  display: grid;
  width: 100%;
}
.report-signature-col {
  padding: 0 8px;
  text-align: center;
}
.report-signature-title {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}
.report-signature-instruction {
  min-height: 14px;
  margin-top: 2px;
  font-size: 9px;
  font-style: italic;
}
.report-signature-space {
  height: 70px;
  position: relative;
}
.report-signature-image {
  max-width: 120px;
  max-height: 65px;
  object-fit: contain;
}
.report-signature-name {
  min-height: 16px;
  font-size: 11px;
  font-weight: 600;
}
.report-signature-position {
  margin-top: 2px;
  font-size: 10px;
}

@media print {
  .report-signature-section {
    break-inside: avoid;
    page-break-inside: avoid;
  }
}
</style>
