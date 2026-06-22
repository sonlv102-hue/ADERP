# Mini ERP — UI Style Guide

Cập nhật: 2026-06-22. File này là nguồn sự thật duy nhất cho chuẩn UI/UX của Mini ERP.

**Bắt buộc đọc file này trước khi sửa bất kỳ Vue page, component, hoặc CSS nào.**

---

## 1. Design Principles

- **Consistency over creativity**: Dùng component/class đã có, không tự design mới.
- **Smallest diff**: Chỉ sửa phần cần thiết, không refactor toàn page vì lý do style.
- **No mixed patterns**: Không trộn raw Tailwind button với `erp-btn-*` trong cùng một page.
- **Accessible first**: Icon-only button phải có `title` hoặc `aria-label`.
- **Mobile aware**: Mọi page mới phải test trên viewport 375px.

---

## 2. Icon Standard

### Thư viện duy nhất: `@heroicons/vue/24/outline`

- **Chỉ dùng một thư viện duy nhất**: `@heroicons/vue/24/outline` (`@heroicons/vue` đã có trong package.json).
- **Không import thêm** lucide, feather, fontawesome, material-icons, ionicons.
- Trong sidebar/nav: import qua `navIcons.js` (`Components/Layout/navIcons.js`).
- Trong page component: dùng inline SVG path (copy từ heroicons.com 24/outline).

### Icon sizes

| Vị trí | Size class | px |
|---|---|---|
| Trong button | `w-4 h-4` | 16px |
| Trong card header / section title | `w-5 h-5` | 20px |
| Dashboard widget lớn | `w-6 h-6` | 24px |
| Sidebar nav | `w-5 h-5` | 20px |

- `stroke-width="1.5"` cho icon navigation (mảnh hơn).
- `stroke-width="2"` cho icon trong button và action (đậm hơn).

### Icon nghiệp vụ chuẩn (Heroicons 24/outline paths)

| Nghiệp vụ | Icon name | Path key trong navIcons |
|---|---|---|
| Dashboard | HomeIcon | `home` |
| Mua hàng / PO | ShoppingBagIcon | `shopping-bag` |
| Bán hàng | ShoppingBagIcon + TruckIcon | `truck` |
| Kho | ArchiveBoxIcon | `archive` |
| Dự án | FolderIcon | `folder` |
| Kế toán / Bút toán | BookOpenIcon | `book-open` |
| Báo cáo | ChartBarIcon | `chart-bar` |
| Nhân sự / Lương | UsersIcon | `users` |
| TSCĐ / CCDC | WrenchIcon | `wrench` |
| Cài đặt | CogIcon | `cog` |
| Thêm mới | `M12 4v16m8-8H4` (PlusIcon) | — |
| Sửa | `M16.862 4.487...` (PencilSquareIcon) | `pencil-alt` |
| Xem chi tiết | `M2.036 12.322...` (EyeIcon) | — |
| Xóa / Hủy | `M14.74 9l-.346 9...` (TrashIcon) | — |
| Đảo / Reverse | ArrowPathIcon | `refresh` |
| Kết chuyển sang 154 | ArrowRightCircleIcon | `arrow-circle-right` |
| Ghi nhận kế toán | CheckCircleIcon | `check-circle` |
| Phiếu thu (PT-) | InboxArrowDownIcon | `inbox` |
| Phiếu chi (PC-) | ArrowUpOnSquareIcon | `share` |
| Cảnh báo / lỗi | ExclamationTriangleIcon | — |
| Thành công | CheckCircleIcon | `check-circle` |
| Tìm kiếm | MagnifyingGlassIcon | `magnifying-glass` |
| Xuất Excel | ArrowDownTrayIcon | — |
| In PDF | PrinterIcon | — |
| Upload | ArrowUpTrayIcon | — |

---

## 3. Color Tokens

### Tailwind primary (xanh dương)
Định nghĩa trong `tailwind.config.js`:
```
primary-50, 100, 200, 500, 600, 700, 800, 900
```

### Semantic color mapping (dùng Tailwind built-in)

| Semantic | Tailwind | Dùng cho |
|---|---|---|
| success | green-* | posted, confirmed, paid, completed, active |
| warning | yellow-* | pending, partial, unpaid, overdue |
| danger | red-* | cancelled, reversed, voided, error |
| info | blue-* | draft, reviewing, received, transferred, not_required |
| neutral | slate-* / gray-* | not_posted, unknown, legacy |

> **Rule**: Dùng `slate-*` cho UI chrome (border, bg, text label). `gray-*` chấp nhận trong các file cũ nhưng file mới ưu tiên `slate-*`.

---

## 4. Button Standard

### CSS classes trong `app.css` — dùng trực tiếp, không viết Tailwind raw

| Variant | Class | Dùng cho |
|---|---|---|
| Primary | `erp-btn-primary` hoặc `btn-primary` | Hành động chính: Thêm, Lưu, Xác nhận, Kết chuyển |
| Secondary | `erp-btn-secondary` hoặc `btn-secondary` | Hành động phụ: Sửa, Xuất, Quay lại |
| Danger | `erp-btn-danger` hoặc `btn-danger` | Xóa, Hủy, Đảo (destructive) |
| Success | `erp-btn-success` | Duyệt, Ghi nhận kế toán (optional) |

Tất cả đã có `min-h-[40px] touch-manipulation` (mobile-friendly).

### Size: không dùng `px-2 py-1` trực tiếp trên button — nếu cần size sm, thêm class riêng

```css
/* Thêm vào app.css nếu cần button nhỏ hơn */
.erp-btn-sm { @apply px-3 py-1.5 text-xs min-h-[32px]; }
```

### Rules

1. Nút hành động chính của trang → `erp-btn-primary`.
2. Nút edit, export, secondary → `erp-btn-secondary`.
3. Nút xóa / hủy / đảo bút toán → `erp-btn-danger`.
4. **Không viết** `class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium"` nữa.
5. Icon trong button: `w-4 h-4` trước text, `stroke-width="2"`.
6. Link style (Inertia `<Link>`) dùng như button: cũng áp dụng `erp-btn-*`.
7. Action text link trong table (Xem / Sửa / Xóa): chấp nhận `text-primary-600 text-xs` hoặc icon-only — nhưng phải nhất quán trong cùng một table.

---

## 5. Badge / Status Standard

### Component: `StatusBadge.vue` (`Components/Shared/StatusBadge.vue`)

**Cách dùng — 2 patterns:**

```vue
<!-- Pattern A: dùng color từ PHP DTO (ưu tiên) -->
<StatusBadge :color="item.status_color">{{ item.status_label }}</StatusBadge>

<!-- Pattern B: dùng status name trực tiếp (frontend-only statuses) -->
<StatusBadge status="draft">Nháp</StatusBadge>
<StatusBadge status="transferred">Đã kết chuyển</StatusBadge>
```

### Canonical status → color mapping

| Status | Color | Màu hiển thị |
|---|---|---|
| `draft` | gray | Nháp |
| `not_posted` | gray | Chưa ghi nhận |
| `pending` | yellow | Chờ xử lý |
| `reviewing` | blue | Đang kiểm tra |
| `confirmed` | green | Đã xác nhận |
| `posted` | green | Đã ghi nhận |
| `valid` | green | Hợp lệ |
| `paid` | green | Đã thanh toán |
| `completed` | green | Hoàn thành |
| `active` | green | Đang hoạt động |
| `transferred` | blue | Đã kết chuyển |
| `received` | blue | Đã nhận |
| `not_required` | blue | Không cần KC |
| `partial` | yellow | Kết chuyển một phần |
| `partial_paid` | yellow | TT một phần |
| `unpaid` | yellow | Chưa thanh toán |
| `overdue` | orange | Quá hạn |
| `cancelled` | red | Đã hủy |
| `reversed` | red | Đã đảo |
| `voided` | red | Đã void |
| `error` | red | Lỗi |
| `data_error` | red | Lỗi dữ liệu WIP |
| `need_supplement` | yellow | Cần bổ sung |

### Rules

1. Không tự tạo badge bằng raw `<span class="bg-green-100 text-green-800 ...">`.
2. Luôn dùng `<StatusBadge>` cho trạng thái.
3. `status_color` và `status_label` phải được PHP Enum cung cấp qua DTO.
4. Nếu status không có PHP Enum (frontend-only), dùng `status` prop với bảng mapping ở trên.

---

## 6. Form Standard

### Components sẵn có

| Component | Path | Dùng cho |
|---|---|---|
| `FormField` | `Components/Shared/FormField.vue` | Wrapper label + error + hint |
| `CurrencyInput` | `Components/Shared/CurrencyInput.vue` | Nhập số tiền VND |
| `RemoteSearchSelect` | `Components/Shared/RemoteSearchSelect.vue` | Dropdown async (danh sách lớn) |
| `SearchableSelect` | `Components/Shared/SearchableSelect.vue` | Dropdown local array (danh sách nhỏ ≤60 items) |

### Input class chuẩn

```vue
<!-- Text / select / date input -->
<input class="erp-input" ... />
<select class="erp-input" ... />
<textarea class="erp-input" ... />

<!-- Khi có lỗi -->
<input class="erp-input erp-input-error" ... />
```

### Pattern chuẩn cho form field

```vue
<FormField label="Nhà cung cấp" required :error="form.errors.supplier_id">
  <RemoteSearchSelect
    v-model="form.supplier_id"
    :display-text="supplierName"
    :search-url="route('search.suppliers')"
    :has-error="!!form.errors.supplier_id"
  />
</FormField>
```

### Khi dùng RemoteSearchSelect vs SearchableSelect

| Trường hợp | Component |
|---|---|
| Nhà cung cấp, khách hàng, sản phẩm, dự án, nhân viên, TK kế toán | `RemoteSearchSelect` |
| Dropdown ít lựa chọn (≤30, không thay đổi) | `<select class="erp-input">` |
| Danh sách đã preload sẵn ≤60 items, cần filter local | `SearchableSelect` |
| Kho, loại hóa đơn, đơn vị tính | `<select class="erp-input">` |

### Rules

1. Label required field: dùng `required` prop của `FormField` → tự hiện dấu `*`.
2. Error inline: truyền `error` prop vào `FormField`, không tự thêm `<p>` error riêng.
3. Hint text: dùng `hint` prop của `FormField`.
4. Form 2 cột: `grid grid-cols-1 sm:grid-cols-2 gap-4`.
5. Form 3 cột: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4`.

---

## 7. Table Standard

### Wrapper

```vue
<div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 border-b border-gray-200">
      <tr>
        <th class="text-left px-5 py-3 font-semibold text-gray-600">...</th>
        <th class="px-5 py-3"></th> <!-- action column: no text -->
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <tr v-for="item in items.data" :key="item.id" class="hover:bg-gray-50">
        <td class="px-5 py-3 ...">...</td>
        <td class="px-5 py-3 text-right whitespace-nowrap">
          <!-- actions -->
        </td>
      </tr>
      <tr v-if="!items.data?.length">
        <td :colspan="N" class="px-5 py-10 text-center text-gray-400">
          Chưa có dữ liệu
        </td>
      </tr>
    </tbody>
  </table>
</div>
```

### Column conventions

| Column type | Alignment | Class |
|---|---|---|
| Mã (code) | left | `font-mono font-medium text-primary-700` |
| Text thông tin | left | `text-gray-900` |
| Text phụ / date | left | `text-gray-600` |
| Số tiền | right | `text-right font-medium text-gray-900` |
| Số tiền âm / warning | right | `text-right text-red-600 font-medium` |
| Trạng thái (StatusBadge) | left | — |
| Actions | right | `text-right whitespace-nowrap` |

### Action column convention (trong table)

```vue
<td class="px-5 py-3 text-right whitespace-nowrap">
  <Link :href="route('...show', item.id)"
    class="text-primary-600 hover:text-primary-800 text-xs font-medium mr-3">Xem</Link>
  <Link :href="route('...edit', item.id)"
    class="text-gray-600 hover:text-gray-800 text-xs font-medium mr-3">Sửa</Link>
  <button @click="deleteItem(item)"
    class="text-red-600 hover:text-red-800 text-xs font-medium">Xóa</button>
</td>
```

### Rules

1. **`overflow-x-auto`** trên wrapper — không dùng `overflow-hidden`.
2. **`min-w-full`** trên `<table>` — không dùng `w-full`.
3. Empty state: luôn có `<tr v-if="!items.data?.length">` với colspan đúng.
4. Action column luôn là cột cuối cùng, `text-right`.
5. Không để actions dàn thành nhiều dòng — dùng `whitespace-nowrap`.
6. Header không có data: `<th class="px-5 py-3"></th>` (trống).

---

## 8. Modal / Drawer Standard

### Component: `Modal.vue` (`Components/Shared/Modal.vue`)

```vue
<Modal :show="showModal" max-width="lg" @close="showModal = false">
  <template #title>Tiêu đề modal</template>

  <!-- Body content -->
  <div class="space-y-4">
    ...
  </div>

  <template #footer>
    <button @click="showModal = false" class="erp-btn-secondary">Hủy</button>
    <button @click="submit" :disabled="form.processing" class="erp-btn-primary">
      Lưu
    </button>
  </template>
</Modal>
```

### max-width tham khảo

| Nội dung | max-width |
|---|---|
| Confirm dialog | `sm` hoặc `md` |
| Form thêm/sửa đơn giản | `lg` |
| Form phức tạp nhiều field | `xl` hoặc `2xl` |
| Form với bảng dữ liệu | `2xl` |

### Rules

1. Header modal: slot `#title` — không để trống.
2. Footer: Hủy (secondary) bên trái, action chính (primary/danger) bên phải.
3. Action chính nằm **bên phải** → `justify-end` (mặc định trong Modal.vue).
4. Không tạo custom modal inline — luôn dùng `Modal.vue`.
5. Trên mobile: tự động bottom-sheet (xử lý sẵn trong Modal.vue).

---

## 9. RemoteSearchSelect Standard

### Các search endpoints đã có (`route('search.*')`)

| Entity | Route | Trả về |
|---|---|---|
| Nhà cung cấp | `search.suppliers` | `{ value, label, code, meta }` |
| Khách hàng | `search.customers` | `{ value, label, code, meta }` |
| Sản phẩm / vật tư | `search.products` | `{ value, label, code, meta }` |
| Dịch vụ | `search.services` | `{ value, label, code }` |
| Nhân viên | `search.employees` | `{ value, label, code }` |
| Tài khoản kế toán | `search.account-codes` | `{ value, label, code }` |
| Dự án | `search.projects` | `{ value, label, code, meta }` |
| Đơn mua (PO) | `search.purchase-orders` | `{ value, label, code, meta }` |

### Pattern chuẩn

```vue
<RemoteSearchSelect
  v-model="form.supplier_id"
  :display-text="form.supplier_name"
  :search-url="route('search.suppliers')"
  placeholder="Tìm nhà cung cấp..."
  :has-error="!!form.errors.supplier_id"
  @change="opt => { form.supplier_name = opt?.label ?? '' }"
/>
```

### Edit mode (pre-load tên đã chọn)

```vue
<!-- Trong defineProps hoặc setup -->
const form = useForm({
  supplier_id: props.item?.supplier_id ?? null,
  supplier_name: props.item?.supplier_name ?? props.item?.supplier?.name ?? '',
})
```

### Rules

1. `display-text` = tên hiển thị khi edit (string) — bắt buộc khi form ở edit mode.
2. `@change` cập nhật tên vào form để giữ khi validate fail.
3. Không preload toàn bộ danh sách vào `<select>` cho supplier/customer/product/employee.
4. Nếu search endpoint chưa có → thêm vào `SearchController` trước.

---

## 10. Page Layout Standard

### Cấu trúc page chuẩn (Index page)

```vue
<AppLayout>
  <div class="space-y-5">
    <!-- 1. Header -->
    <div class="erp-page-header">
      <h1 class="text-2xl font-bold text-gray-900">Tiêu đề trang</h1>
      <div class="flex gap-2 flex-wrap">
        <Link :href="route('...create')" class="erp-btn-primary">
          <svg class="w-4 h-4" ...>...</svg>
          Thêm mới
        </Link>
      </div>
    </div>

    <!-- 2. Filters -->
    <div class="flex gap-3 flex-wrap">
      <input v-model="search" class="erp-input w-full sm:w-64" placeholder="Tìm kiếm..." />
      <select v-model="statusFilter" class="erp-input w-full sm:w-auto">...</select>
    </div>

    <!-- 3. Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="min-w-full text-sm">...</table>
    </div>

    <!-- 4. Pagination -->
    <Pagination :links="items.links" :meta="items.meta" />
  </div>
</AppLayout>
```

### Show page header

```vue
<div class="flex items-start justify-between flex-wrap gap-y-3">
  <div class="flex items-center gap-3">
    <Link :href="route('...index')" class="text-gray-500 hover:text-gray-700">
      <!-- back arrow SVG w-5 h-5 -->
    </Link>
    <div>
      <div class="flex items-center gap-3">
        <h1 class="text-2xl font-bold text-gray-900">{{ item.code }}</h1>
        <StatusBadge :color="item.status_color">{{ item.status_label }}</StatusBadge>
      </div>
      <p class="text-sm text-gray-500 mt-0.5">{{ subtitle }}</p>
    </div>
  </div>
  <div class="flex gap-2 flex-wrap items-center">
    <!-- action buttons -->
  </div>
</div>
```

### Rules

1. `erp-page-header` = `flex items-center justify-between flex-wrap gap-y-3 gap-x-4` — dùng class này.
2. Section spacing: `<div class="space-y-5">` hoặc `space-y-6` cho Show pages.
3. Card wrapper: `<div class="bg-white rounded-xl border border-gray-200">`.
4. Section divider trong card: `<div class="border-b border-gray-100">`.
5. Tab nav trong card: `class="flex border-b border-gray-200 overflow-x-auto"`.
6. Summary cards: `grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4`.

---

## 11. Mobile Standard

- **Breakpoints**: `sm:` (640px), `md:` (768px), `lg:` (1024px).
- **Tables**: luôn `overflow-x-auto` trên wrapper + `min-w-full` trên table.
- **Form grids**: `grid-cols-1 sm:grid-cols-2` — không `grid-cols-2` trực tiếp.
- **Button groups**: `flex gap-2 flex-wrap` để tránh tràn màn hình nhỏ.
- **Search inputs**: `w-full sm:w-64` — không `w-64` trực tiếp.
- **Tab nav trong card**: `overflow-x-auto` để scroll ngang trên mobile.
- **Modal**: tự động bottom-sheet (Modal.vue đã xử lý).
- **Touch targets**: `min-h-[40px]` — các `erp-btn-*` và `erp-input` đã có sẵn.

---

## 12. Do / Don't

### ✅ DO

```vue
<!-- Button -->
<button class="erp-btn-primary">Lưu</button>
<Link class="erp-btn-secondary">Sửa</Link>
<button class="erp-btn-danger">Xóa</button>

<!-- Input -->
<input class="erp-input" />

<!-- Badge -->
<StatusBadge :color="item.status_color">{{ item.status_label }}</StatusBadge>
<StatusBadge status="transferred">Đã kết chuyển</StatusBadge>

<!-- Table -->
<div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
  <table class="min-w-full text-sm">

<!-- RemoteSearch cho entity lớn -->
<RemoteSearchSelect v-model="form.supplier_id" :search-url="route('search.suppliers')" />

<!-- FormField wrapper -->
<FormField label="Email" required :error="form.errors.email">
  <input v-model="form.email" class="erp-input" />
</FormField>
```

### ❌ DON'T

```vue
<!-- Button: raw Tailwind -->
<button class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
  Lưu
</button>

<!-- Badge: raw span -->
<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs bg-green-100 text-green-800">
  Đã ghi nhận
</span>

<!-- Input: raw Tailwind -->
<input class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />

<!-- Table: overflow-hidden -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
  <table class="w-full text-sm">

<!-- Large select preload -->
<select v-model="form.supplier_id">
  <option v-for="s in suppliers" :key="s.id">{{ s.name }}</option>
</select>
```

---

## 13. Checklist trước khi merge UI

```
[ ] Buttons dùng erp-btn-primary / secondary / danger — không raw Tailwind
[ ] Input fields dùng erp-input — không inline border/ring/rounded
[ ] StatusBadge dùng color từ DTO hoặc status prop — không raw <span>
[ ] Table wrapper: overflow-x-auto + table: min-w-full
[ ] Empty state row có colspan đúng và text hướng dẫn
[ ] Form grids: grid-cols-1 sm:grid-cols-2 (không grid-cols-2 trực tiếp)
[ ] Page header dùng erp-page-header hoặc flex justify-between flex-wrap gap-y-3
[ ] Modal dùng Modal.vue — không custom div modal
[ ] Entity lớn (supplier/customer/product/employee) dùng RemoteSearchSelect
[ ] FormField wrapper: label + required + error prop
[ ] Icon: w-4 h-4 trong button, w-5 h-5 trong header/card
[ ] Icon-only button có title hoặc aria-label
[ ] Test responsive tại 375px
[ ] php artisan test pass (nếu có thay đổi controller/service)
[ ] npm run build pass
```

---

## 14. Các màn ưu tiên cần audit / sửa (backlog)

| Màn | Vấn đề | Ưu tiên |
|---|---|---|
| Tất cả Index pages | Buttons chưa dùng `erp-btn-*` | Cao |
| Chi phí PS (Projects/Show tab) | StatusBadge custom, nút Kết chuyển | Cao |
| Dashboard | Layout KPI responsive | Trung bình |
| Báo cáo (Reports/) | Nút Xuất chưa nhất quán | Trung bình |
| Hóa đơn bán (Invoices/) | Action links trong table | Thấp |
| Nhập kho / Xuất kho | Form grids cần sm: breakpoint | Thấp |

Các màn này sẽ được sửa dần theo sprint, ưu tiên không block nghiệp vụ.
