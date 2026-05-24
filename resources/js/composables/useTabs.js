import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const MAX_TABS = 8;

const URL_TITLES = {
  '/dashboard':                      'Dashboard',
  '/notifications':                  'Thông báo',
  '/crm/customers':                  'Khách hàng',
  '/crm/leads':                      'KH tiềm năng',
  '/sales/quotations':               'Báo giá',
  '/sales/orders':                   'Đơn hàng',
  '/sales/contracts':                'HĐ bán',
  '/sales/commissions':              'Hoa hồng',
  '/sales/sales-returns':            'Trả hàng bán',
  '/warehouse/warehouses':           'Kho hàng',
  '/warehouse/stock-entries':        'Nhập kho',
  '/warehouse/stock-exits':          'Xuất kho',
  '/warehouse/stock-transfers':      'Chuyển kho',
  '/warehouse/suppliers':            'Nhà CC',
  '/catalog/product-categories':     'Danh mục SP',
  '/catalog/products':               'Sản phẩm',
  '/catalog/price-lists':            'Bảng giá',
  '/catalog/services':               'Dịch vụ',
  '/projects/projects':              'Dự án',
  '/support/tickets':                'Tickets',
  '/support/warranties':             'Bảo hành',
  '/purchasing/purchase-orders':     'Đơn mua',
  '/purchasing/purchase-contracts':  'HĐ mua',
  '/purchasing/purchase-invoices':   'HĐ đầu vào',
  '/purchasing/purchase-returns':    'Trả hàng mua',
  '/accounting/invoices':            'Hóa đơn',
  '/documents/documents':            'Chứng từ',
  '/reports/profit/orders':          'LN đơn hàng',
  '/reports/profit/projects':        'LN dự án',
  '/reports/ar/aging':               'Công nợ phải thu',
  '/reports/ap/aging':               'Công nợ phải trả',
  '/reports/vat':                    'Báo cáo VAT',
  '/reports/inventory':              'Tồn kho',
  '/reports/cash_flow':              'Thu chi',
  '/reports/income_statement':       'Kết quả KD',
  '/reports/balance_sheet':          'Cân đối KT',
  '/reports/trial_balance':          'Cân đối PS',
  '/reports/general_journal':        'Nhật ký chung',
  '/reports/account_ledger':         'Sổ chi tiết TK',
  '/reports/expense_detail':         'Chi tiết CP',
  '/reports/fixed_assets':           'Tài sản CĐ',
  '/admin/users':                    'Người dùng',
  '/admin/roles':                    'Phân quyền',
  '/admin/settings':                 'Cài đặt',
  '/admin/fixed-assets':             'TSCĐ',
  '/admin/activity-logs':            'Nhật ký HĐ',
};

function titleFromUrl(url) {
  const path = url.split('?')[0];
  const match = Object.entries(URL_TITLES)
    .filter(([key]) => path === key || path.startsWith(key + '/'))
    .sort((a, b) => b[0].length - a[0].length)[0];
  return match ? match[1] : (path.split('/').filter(Boolean).at(-1)?.replace(/-/g, ' ') || 'Trang');
}

function load() {
  try { return JSON.parse(localStorage.getItem('erp_tabs') || '[]'); } catch { return []; }
}

function save(t) { localStorage.setItem('erp_tabs', JSON.stringify(t)); }

// Singleton — shared across all component instances
const tabs = ref(load());

export function useTabs() {
  function openTab(url) {
    const key = url.split('?')[0];
    if (tabs.value.find(t => t.url === key)) return;
    if (tabs.value.length >= MAX_TABS) tabs.value.shift();
    tabs.value.push({ url: key, title: titleFromUrl(key) });
    save(tabs.value);
  }

  function closeTab(url, currentUrl) {
    const idx = tabs.value.findIndex(t => t.url === url);
    if (idx === -1) return;
    tabs.value.splice(idx, 1);
    save(tabs.value);
    if (url === currentUrl.split('?')[0] && tabs.value.length > 0) {
      router.visit(tabs.value[Math.min(idx, tabs.value.length - 1)].url);
    }
  }

  return { tabs, openTab, closeTab };
}
