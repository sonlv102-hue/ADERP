<template>
  <div class="relative" ref="containerRef">
    <div
      class="flex items-center w-full border rounded-lg overflow-hidden bg-white transition-colors"
      :class="[
        hasError ? 'border-red-400 focus-within:ring-2 focus-within:ring-red-100' : 'border-gray-300 focus-within:border-primary-500 focus-within:ring-2 focus-within:ring-primary-500/20',
      ]"
    >
      <input
        ref="inputRef"
        v-model="query"
        type="text"
        :placeholder="isOpen ? 'Nhập mã hoặc tên sản phẩm...' : (displayLabel || placeholder)"
        autocomplete="off"
        class="flex-1 px-3 py-2 text-sm outline-none bg-transparent min-w-0"
        :class="(!isOpen && displayLabel) ? 'text-gray-900' : 'text-gray-400'"
        @focus="onFocus"
        @input="onInput"
        @keydown="onKeydown"
      />

      <span v-if="loading" class="px-2 text-gray-400 flex-shrink-0">
        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
        </svg>
      </span>
      <button
        v-else-if="modelValue != null && modelValue !== '' && !disabled"
        type="button"
        tabindex="-1"
        class="px-2 text-gray-400 hover:text-gray-600 flex-shrink-0"
        @click.stop="clear"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
      <span v-else class="px-2 text-gray-400 flex-shrink-0 pointer-events-none">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
      </span>
    </div>

    <Teleport to="body">
      <div
        v-if="isOpen"
        ref="dropdownRef"
        class="fixed z-[9999] bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden"
        :style="dropdownStyle"
      >
        <div v-if="results.length" class="max-h-64 overflow-y-auto overscroll-contain">
          <button
            v-for="(opt, i) in results"
            :key="opt.value"
            type="button"
            class="w-full text-left px-3 py-2.5 text-sm flex items-center gap-2 transition-colors"
            :class="i === highlightedIndex ? 'bg-primary-50 text-primary-700' : 'text-gray-700 hover:bg-gray-50'"
            @mousedown.prevent="select(opt)"
            @mousemove="highlightedIndex = i"
          >
            <span class="font-mono text-xs text-gray-400 flex-shrink-0 w-20 truncate">{{ opt.code }}</span>
            <span class="truncate flex-1 font-medium">{{ opt.label }}</span>
            <span v-if="opt.meta" class="ml-auto text-xs text-gray-400 flex-shrink-0">{{ opt.meta }}</span>
          </button>
        </div>
        <div v-else-if="!loading" class="px-3 py-3 text-sm text-gray-400 text-center">
          {{ searched ? 'Không tìm thấy sản phẩm' : 'Nhập để tìm kiếm...' }}
        </div>
        <div v-else class="px-3 py-3 text-sm text-gray-400 text-center flex items-center justify-center gap-2">
          <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
          </svg>
          Đang tìm...
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  modelValue:  { type: [Number, String, null], default: null },
  displayText: { type: String, default: '' },
  searchUrl:   { type: String, default: '' },
  placeholder: { type: String, default: '-- Tìm sản phẩm --' },
  disabled:    { type: Boolean, default: false },
  hasError:    { type: Boolean, default: false },
  extraParams: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue', 'select'])

const containerRef     = ref(null)
const inputRef         = ref(null)
const dropdownRef      = ref(null)
const query            = ref('')
const isOpen           = ref(false)
const loading          = ref(false)
const results          = ref([])
const searched         = ref(false)
const highlightedIndex = ref(0)
const dropdownStyle    = ref({})
const internalDisplayText = ref(props.displayText)
let debounceTimer      = null
let abortController    = null

const displayLabel = computed(() => internalDisplayText.value || props.displayText)

watch(() => props.displayText, (val) => { internalDisplayText.value = val })
watch(() => props.modelValue, (val) => {
  if (!val) internalDisplayText.value = ''
})

function resolveUrl() {
  if (props.searchUrl) return props.searchUrl
  return window.route ? window.route('search.products') : '/api/search/products'
}

function positionDropdown() {
  if (!containerRef.value) return
  const rect = containerRef.value.getBoundingClientRect()
  const spaceBelow = window.innerHeight - rect.bottom
  const dropHeight = 280
  const top = spaceBelow >= dropHeight
    ? rect.bottom + window.scrollY + 4
    : rect.top + window.scrollY - dropHeight - 4
  dropdownStyle.value = {
    top: `${top}px`,
    left: `${rect.left + window.scrollX}px`,
    width: `${Math.max(rect.width, 280)}px`,
  }
}

async function fetchResults(q) {
  abortController?.abort()
  abortController = new AbortController()
  loading.value = true
  searched.value = false
  try {
    const params = new URLSearchParams({ q, ...props.extraParams })
    const res = await fetch(`${resolveUrl()}?${params}`, {
      signal: abortController.signal,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const json = await res.json()
    results.value = json.data ?? []
    searched.value = true
    highlightedIndex.value = 0
  } catch (err) {
    if (err.name !== 'AbortError') { results.value = []; searched.value = true }
  } finally {
    loading.value = false
  }
}

function onFocus() {
  if (props.disabled) return
  isOpen.value = true
  nextTick(positionDropdown)
  if (results.value.length === 0 || query.value === '') fetchResults(query.value)
}

function onInput() {
  isOpen.value = true
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => fetchResults(query.value.trim()), 300)
}

function select(opt) {
  internalDisplayText.value = `${opt.code} - ${opt.label}`
  emit('update:modelValue', opt.value)
  emit('select', opt)
  query.value = ''
  isOpen.value = false
}

function clear() {
  internalDisplayText.value = ''
  emit('update:modelValue', null)
  emit('select', null)
  query.value = ''
  results.value = []
  searched.value = false
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
    highlightedIndex.value = Math.min(highlightedIndex.value + 1, results.value.length - 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    highlightedIndex.value = Math.max(highlightedIndex.value - 1, 0)
  } else if (e.key === 'Enter') {
    e.preventDefault()
    if (results.value[highlightedIndex.value]) select(results.value[highlightedIndex.value])
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

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onUnmounted(() => {
  document.removeEventListener('mousedown', onClickOutside)
  abortController?.abort()
  clearTimeout(debounceTimer)
})
</script>
