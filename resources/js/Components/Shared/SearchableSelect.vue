<template>
  <div class="relative" ref="containerRef">
    <!-- Trigger -->
    <div
      class="flex items-center w-full border rounded-lg overflow-hidden bg-white transition-colors"
      :class="[
        hasError ? 'border-red-400 focus-within:ring-2 focus-within:ring-red-100' : 'border-slate-300 focus-within:border-primary-500 focus-within:ring-2 focus-within:ring-primary-500/20',
        disabled ? 'bg-slate-50 cursor-not-allowed' : '',
      ]"
    >
      <input
        ref="inputRef"
        v-model="query"
        type="text"
        :placeholder="selectedLabel || placeholder"
        :disabled="disabled"
        autocomplete="off"
        class="flex-1 px-3 py-2 text-sm outline-none bg-transparent min-w-0"
        :class="selectedLabel && !isOpen ? 'text-slate-900' : 'text-slate-400 placeholder:text-slate-400'"
        @focus="onFocus"
        @input="isOpen = true; highlightedIndex = 0"
        @keydown="onKeydown"
      />
      <!-- Clear -->
      <button
        v-if="modelValue != null && modelValue !== '' && !disabled"
        type="button"
        tabindex="-1"
        class="px-2 text-slate-400 hover:text-slate-600 flex-shrink-0"
        @click.stop="clear"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
      <!-- Chevron -->
      <span class="px-2 text-slate-400 flex-shrink-0 pointer-events-none">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7"/>
        </svg>
      </span>
    </div>

    <!-- Dropdown -->
    <Teleport to="body">
      <div
        v-if="isOpen"
        ref="dropdownRef"
        class="fixed z-[9999] bg-white border border-slate-200 rounded-xl shadow-lg overflow-hidden"
        :style="dropdownStyle"
      >
        <div v-if="filtered.length" class="max-h-56 overflow-y-auto overscroll-contain">
          <button
            v-for="(opt, i) in filtered"
            :key="opt.value"
            type="button"
            class="w-full text-left px-3 py-2 text-sm flex items-center gap-2 transition-colors"
            :class="i === highlightedIndex ? 'bg-primary-50 text-primary-700' : 'text-slate-700 hover:bg-slate-50'"
            @mousedown.prevent="select(opt)"
            @mousemove="highlightedIndex = i"
          >
            <span v-if="opt.code" class="font-mono text-xs text-slate-400 flex-shrink-0 w-20 truncate">{{ opt.code }}</span>
            <span class="truncate flex-1">{{ opt.label }}</span>
            <span v-if="opt.meta" class="ml-auto text-xs text-slate-400 flex-shrink-0">{{ opt.meta }}</span>
          </button>
        </div>
        <div v-else class="px-3 py-3 text-sm text-slate-400 text-center">
          {{ emptyText }}
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
  modelValue:  { type: [Number, String, null], default: null },
  options:     { type: Array, default: () => [] },
  placeholder: { type: String, default: '-- Chọn --' },
  disabled:    { type: Boolean, default: false },
  hasError:    { type: Boolean, default: false },
  emptyText:   { type: String, default: 'Không tìm thấy kết quả' },
})

const emit = defineEmits(['update:modelValue', 'change'])

const containerRef     = ref(null)
const inputRef         = ref(null)
const dropdownRef      = ref(null)
const query            = ref('')
const isOpen           = ref(false)
const highlightedIndex = ref(0)
const dropdownStyle    = ref({})

const selectedLabel = computed(() => {
  if (props.modelValue == null || props.modelValue === '') return ''
  const opt = props.options.find(o => String(o.value) === String(props.modelValue))
  return opt ? opt.label : ''
})

const filtered = computed(() => {
  const q = query.value.toLowerCase().trim()
  const pool = q
    ? props.options.filter(o =>
        o.label?.toLowerCase().includes(q) ||
        o.code?.toLowerCase().includes(q) ||
        o.meta?.toLowerCase().includes(q)
      )
    : props.options
  return pool.slice(0, 60)
})

function positionDropdown() {
  if (!containerRef.value) return
  const rect = containerRef.value.getBoundingClientRect()
  const spaceBelow = window.innerHeight - rect.bottom
  const dropHeight = 240
  const top = spaceBelow >= dropHeight
    ? rect.bottom + window.scrollY + 4
    : rect.top + window.scrollY - dropHeight - 4
  dropdownStyle.value = {
    top: `${top}px`,
    left: `${rect.left + window.scrollX}px`,
    width: `${rect.width}px`,
  }
}

function onFocus() {
  if (props.disabled) return
  query.value = ''
  highlightedIndex.value = 0
  isOpen.value = true
  nextTick(positionDropdown)
}

function select(opt) {
  emit('update:modelValue', opt.value)
  emit('change', opt)
  query.value = ''
  isOpen.value = false
}

function clear() {
  emit('update:modelValue', null)
  emit('change', null)
  query.value = ''
  isOpen.value = false
  inputRef.value?.focus()
}

function onKeydown(e) {
  if (!isOpen.value) {
    if (e.key === 'ArrowDown' || e.key === ' ') { e.preventDefault(); onFocus() }
    return
  }
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    highlightedIndex.value = Math.min(highlightedIndex.value + 1, filtered.value.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0)
  } else if (e.key === 'Enter') {
    e.preventDefault()
    if (filtered.value[highlightedIndex.value]) select(filtered.value[highlightedIndex.value])
  } else if (e.key === 'Escape' || e.key === 'Tab') {
    isOpen.value = false
    query.value = ''
  }
}

function onClickOutside(e) {
  if (
    containerRef.value && !containerRef.value.contains(e.target) &&
    dropdownRef.value && !dropdownRef.value.contains(e.target)
  ) {
    isOpen.value = false
    query.value = ''
  }
}

watch(isOpen, (val) => { if (val) nextTick(positionDropdown) })
watch(() => props.options, () => { highlightedIndex.value = 0 })

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside))
</script>
