<template>
  <div class="relative" ref="containerRef">
    <div
      class="flex items-center w-full border rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-primary-500"
      :class="hasError ? 'border-red-500' : 'border-gray-300'"
    >
      <input
        ref="inputRef"
        v-model="query"
        type="text"
        :placeholder="selectedLabel || placeholder"
        :class="[
          'flex-1 px-3 py-2 outline-none text-sm min-w-0',
          selectedLabel && !isOpen ? 'text-gray-900' : 'text-gray-500'
        ]"
        @focus="onFocus"
        @input="onInput"
        @keydown="onKeydown"
        autocomplete="off"
      />
      <button
        v-if="modelValue"
        type="button"
        @click.stop="clear"
        class="px-2 text-gray-400 hover:text-gray-600 flex-shrink-0"
        tabindex="-1"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
      <span class="px-2 text-gray-400 flex-shrink-0 pointer-events-none">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
      </span>
    </div>

    <div
      v-if="isOpen && filtered.length"
      class="absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-56 overflow-y-auto"
    >
      <button
        v-for="(option, i) in filtered"
        :key="option.id"
        type="button"
        class="w-full text-left px-3 py-2 text-sm hover:bg-primary-50 flex items-center gap-2"
        :class="i === highlightedIndex ? 'bg-primary-50 text-primary-700' : 'text-gray-700'"
        @click="select(option)"
        @mouseover="highlightedIndex = i"
      >
        <span class="font-mono text-xs text-gray-400 flex-shrink-0">{{ option.code }}</span>
        <span class="truncate">{{ option.name }}</span>
        <span v-if="option.unit" class="ml-auto text-xs text-gray-400 flex-shrink-0">{{ option.unit }}</span>
      </button>
    </div>

    <div
      v-else-if="isOpen && query.length >= 1 && !filtered.length"
      class="absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg px-3 py-2 text-sm text-gray-400"
    >
      Không tìm thấy sản phẩm
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  options:     { type: Array, default: () => [] },
  modelValue:  { type: [Number, String, null], default: null },
  placeholder: { type: String, default: '-- Tìm sản phẩm --' },
  hasError:    { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'select']);

const containerRef    = ref(null);
const inputRef        = ref(null);
const query           = ref('');
const isOpen          = ref(false);
const highlightedIndex = ref(0);

const selectedLabel = computed(() => {
  if (!props.modelValue) return '';
  const opt = props.options.find(o => o.id === props.modelValue);
  return opt ? `${opt.code} - ${opt.name}` : '';
});

const filtered = computed(() => {
  const q = query.value.toLowerCase().trim();
  if (!q) return props.options.slice(0, 50);
  return props.options.filter(o =>
    o.name?.toLowerCase().includes(q) || o.code?.toLowerCase().includes(q)
  ).slice(0, 50);
});

function onFocus() {
  query.value = '';
  isOpen.value = true;
  highlightedIndex.value = 0;
}

function onInput() {
  isOpen.value = true;
  highlightedIndex.value = 0;
}

function select(option) {
  emit('update:modelValue', option.id);
  emit('select', option);
  query.value = '';
  isOpen.value = false;
}

function clear() {
  emit('update:modelValue', null);
  emit('select', null);
  query.value = '';
  isOpen.value = false;
  inputRef.value?.focus();
}

function onKeydown(e) {
  if (!isOpen.value) return;

  if (e.key === 'ArrowDown') {
    e.preventDefault();
    highlightedIndex.value = Math.min(highlightedIndex.value + 1, filtered.value.length - 1);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0);
  } else if (e.key === 'Enter') {
    e.preventDefault();
    if (filtered.value[highlightedIndex.value]) {
      select(filtered.value[highlightedIndex.value]);
    }
  } else if (e.key === 'Escape') {
    isOpen.value = false;
    query.value = '';
  }
}

function onClickOutside(e) {
  if (containerRef.value && !containerRef.value.contains(e.target)) {
    isOpen.value = false;
    query.value = '';
  }
}

onMounted(() => document.addEventListener('mousedown', onClickOutside));
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside));
</script>
