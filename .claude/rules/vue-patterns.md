# Mini ERP — Vue Patterns

## Index Page Pattern
```vue
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';
import { usePermission } from '@/composables/usePermission';

const { hasPermission: can } = usePermission();
const props = defineProps({ items: Object, filters: Object, statuses: Array });

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

function applyFilters() {
  router.get(
    route('module.items.index'), 
    { search: search.value, status: status.value }, 
    { preserveState: true }
  );
}
</script>
```

## Form Page Pattern
```vue
<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({ 
  item: Object, 
  // lookup arrays 
});

const form = useForm({
  field: props.item?.field ?? '',
});

function submit() {
  if (props.item) {
    form.put(route('module.items.update', props.item.id));
  } else {
    form.post(route('module.items.store'));
  }
}
</script>
```

## StatusBadge Usage
```vue
<StatusBadge :color="item.status_color">
  {{ item.status_label }}
</StatusBadge>
```

## Permission Guard
```vue
<button v-if="can('module.manage')" @click="doAction">Action</button>
```

## File Upload (Inertia)
Use `FormData` + `router.post()` — **NOT** `useForm` + `forceFormData`:
```js
function upload(file) {
  const fd = new FormData();
  fd.append('file', file);
  router.post(route('module.items.upload', id), fd, { forceFormData: true });
}
```

## useTabs Composable
Tab bar is auto-managed. `AppLayout.vue` listens to `router.on('navigate')` and calls `openTab(url)`.
- Tabs persist in localStorage (key: `erp_tabs`, max 8)
- `TabBar.vue` shows only when `tabs.length > 0`
- URL-to-title mapping in `useTabs.js` — update `URL_TITLES` map when adding new routes

## formatVnd Helper
```js
function formatVnd(value) {
  return new Intl.NumberFormat('vi-VN').format(value || 0) + ' ₫';
}
```
