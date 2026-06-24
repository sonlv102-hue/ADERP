<template>
  <a :href="exportUrl"
     :class="['erp-btn-secondary flex items-center gap-1.5', { 'opacity-50 pointer-events-none': disabled }]"
     :title="label"
     target="_blank"
     rel="noopener">
    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round"
        d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
    </svg>
    {{ label }}
  </a>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  endpoint: { type: String, required: true },
  filters:  { type: Object, default: () => ({}) },
  label:    { type: String, default: 'Xuất Excel' },
  disabled: { type: Boolean, default: false },
});

const exportUrl = computed(() => {
  const params = new URLSearchParams();
  for (const [key, val] of Object.entries(props.filters)) {
    if (val !== null && val !== undefined && val !== '') {
      params.set(key, String(val));
    }
  }
  const qs = params.toString();
  return qs ? `${props.endpoint}?${qs}` : props.endpoint;
});
</script>
