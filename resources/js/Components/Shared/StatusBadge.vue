<template>
  <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', colorClass]">
    <slot />
  </span>
</template>

<script setup>
import { computed } from 'vue';

// Canonical status → color mapping (see .claude/rules/ui-style-guide.md §5)
const STATUS_COLORS = {
  draft: 'gray', not_posted: 'gray',
  pending: 'yellow', unpaid: 'yellow', partial: 'yellow', partial_paid: 'yellow', need_supplement: 'yellow',
  overdue: 'orange',
  confirmed: 'green', posted: 'green', valid: 'green', paid: 'green', completed: 'green', active: 'green',
  transferred: 'blue', received: 'blue', not_required: 'blue', reviewing: 'blue',
  cancelled: 'red', reversed: 'red', voided: 'red', error: 'red', data_error: 'red',
};

const COLOR_CLASSES = {
  green:  'bg-green-100 text-green-800',
  red:    'bg-red-100 text-red-800',
  yellow: 'bg-yellow-100 text-yellow-800',
  blue:   'bg-blue-100 text-blue-800',
  gray:   'bg-gray-100 text-gray-800',
  purple: 'bg-purple-100 text-purple-800',
  orange: 'bg-orange-100 text-orange-800',
  indigo: 'bg-indigo-100 text-indigo-800',
};

const props = defineProps({
  color:  { type: String, default: '' },   // raw color: 'green' | 'red' | ...
  status: { type: String, default: '' },   // semantic: 'draft' | 'posted' | ...
});

const colorClass = computed(() => {
  const resolved = props.color || STATUS_COLORS[props.status] || 'gray';
  return COLOR_CLASSES[resolved] ?? COLOR_CLASSES.gray;
});
</script>
