<template>
  <div class="erp-table-wrap" :style="maxHeight ? { maxHeight, overflowY: 'auto' } : null">
    <slot />
  </div>
</template>

<script setup>
// Standard scroll container for data tables: horizontal scroll always on
// (erp-table-wrap = overflow-x-auto), optional vertical scroll when maxHeight
// is given (pair with the .erp-table-sticky-head class on <thead> for large lists).
defineProps({
  maxHeight: { type: String, default: null },
});
</script>
