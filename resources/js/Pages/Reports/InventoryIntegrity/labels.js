// Nhãn hiển thị cho báo cáo Kiểm soát sai lệch tồn kho và kế toán
export const RULE_LABELS = {
  NEGATIVE_STOCK: 'Tồn âm', PHANTOM_STOCK: 'Tồn kho ảo', QUANTITY_MISMATCH: 'Lệch số lượng', MISSING_BALANCE: 'Thiếu dòng AVCO',
  AVAILABILITY_MISMATCH: 'Lệch tồn khả dụng', VALUE_MISMATCH: 'Lệch giá trị', COST_MISMATCH: 'Lệch giá vốn', ALLOCATION_MISMATCH: 'Lệch phân bổ lô',
  DOCUMENT_SEQUENCE_MISMATCH: 'Xuất trước nhập', DOCUMENT_STATUS_MISMATCH: 'Trạng thái chứng từ', DUPLICATE_TRANSACTION: 'Giao dịch trùng',
  MISSING_DOCUMENT_LINK: 'Thiếu liên kết dòng hàng', MISSING_ACCOUNTING_ENTRY: 'Thiếu bút toán', INVENTORY_GL_MISMATCH: 'Lệch kho – TK156',
  PROJECT_WIP_MISMATCH: 'Lệch WIP 154', ZERO_QTY_WITH_VALUE: 'Hết hàng còn giá trị', MANUAL_JE_REVIEW: 'JE thủ công cần rà soát',
  ACCOUNT_MAPPING_MISMATCH: 'Sai TK đối ứng', EXPECTED_DIFFERENCE: 'Chênh làm tròn', POLICY_DECISION: 'Chờ quyết định chính sách',
  RETURN_WITHOUT_JE: 'Trả hàng chưa có JE',
}

export const STATUS_LABELS = {
  OPEN: 'Mở', ASSIGNED: 'Đã giao', IN_PROGRESS: 'Đang xử lý', PENDING_ACCOUNTING: 'Chờ kế toán', PENDING_TECHNICAL: 'Chờ DEV',
  PENDING_POLICY: 'Chờ chính sách', PENDING_EVIDENCE: 'Chờ bằng chứng', PENDING_VERIFICATION: 'Chờ xác minh', RESOLVED: 'Đã xử lý',
  ACCEPTED_DIFFERENCE: 'Chênh lệch hợp lệ',
}

export const STATUS_COLORS = {
  OPEN: 'red', ASSIGNED: 'blue', IN_PROGRESS: 'blue', PENDING_ACCOUNTING: 'yellow', PENDING_TECHNICAL: 'yellow', PENDING_POLICY: 'purple',
  PENDING_EVIDENCE: 'yellow', PENDING_VERIFICATION: 'yellow', RESOLVED: 'green', ACCEPTED_DIFFERENCE: 'gray',
}

export const CATEGORY_LABELS = {
  TECHNICAL: 'DEV', ACCOUNTING: 'Kế toán', BUSINESS: 'Nghiệp vụ', POLICY_DECISION: 'Chính sách', PENDING_EVIDENCE: 'Thiếu bằng chứng', EXPECTED: 'Hợp lệ',
}

export const SEVERITY = { high: ['Cao', 'red'], medium: ['Trung bình', 'yellow'], low: ['Thấp', 'blue'], info: ['Thông tin', 'gray'] }

export const KPI_GROUP_LABELS = {
  quantity: 'Sai lệch số lượng', value: 'Sai lệch giá trị', cost: 'Sai lệch giá vốn', negative: 'Tồn âm', phantom: 'Tồn kho ảo',
  document: 'Sai lệch logic chứng từ', missing_je: 'Thiếu bút toán', accounting: 'Sai lệch kế toán', expected: 'Chênh lệch làm tròn',
  policy: 'Chủ đề chính sách',
}

export const BUCKET_LABELS = {
  dev: 'Chờ DEV', accounting: 'Chờ kế toán', verification: 'Chờ xác minh', policy: 'Chờ quyết định chính sách',
  resolved: 'Đã xử lý', accepted: 'Chênh lệch hợp lệ',
}

export const SCOPE_LABELS = { all: 'Toàn hệ thống', product: 'Sản phẩm', warehouse: 'Kho', project: 'Dự án', document: 'Chứng từ', issue: 'Vấn đề' }

export function fmtNum(v, digits = 3) {
  if (v === null || v === undefined || v === '') return '—' // chưa xác định: không hiển thị 0
  return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: digits }).format(Number(v))
}

export function fmtVnd(v) {
  return fmtNum(v, 2)
}
