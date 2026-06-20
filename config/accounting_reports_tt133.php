<?php

/**
 * Báo cáo tình hình tài chính — Mẫu B01a-DNN
 * Thông tư 133/2016/TT-BTC
 *
 * Cấu trúc mỗi item:
 *   item_code   — mã chỉ tiêu B01a-DNN
 *   item_name   — tên chỉ tiêu
 *   parent_code — mã cha (null = dòng gốc)
 *   accounts    — danh sách TK cụ thể (EXACT, không prefix)
 *   balance_side:
 *     'debit'         — lấy dư Nợ: sumExact() (debit-normal trả dr-cr, credit-normal trả cr-dr, lấy dương)
 *     'credit'        — lấy dư Có: sumExact() (credit-normal trả cr-dr, cho phép âm với TK 421)
 *     'debit_detail'  — tài khoản lưỡng tính: tổng phần dư Nợ thực tế từng TK (dr-cr > 0)
 *     'credit_detail' — tài khoản lưỡng tính: tổng phần dư Có thực tế từng TK (cr-dr > 0)
 *     'credit_only'   — chỉ lấy dư Có (bỏ qua TK dư Nợ), dùng cho TK 333
 *     'formula'       — tổng các item_code khác
 *   negative    — true = hiển thị âm (hao mòn, dự phòng)
 *   note        — ghi chú kế toán
 *
 * Quan trọng: items phải có thứ tự children trước parents để evaluate formula tuần tự.
 */

return [
    'report_code' => 'B01a-DNN',
    'report_name' => 'Báo cáo tình hình tài chính',
    'circular'    => 'Thông tư 133/2016/TT-BTC',

    // ─────────────────────────────────────────────────────────────────────────
    // PHẦN TÀI SẢN  (items xếp: children trước → formulae sau)
    // ─────────────────────────────────────────────────────────────────────────
    'assets' => [

        // ── I. Tiền và tương đương tiền (mã 110) ────────────────────────────
        [
            'item_code'    => '110',
            'item_name'    => 'I. Tiền và các khoản tương đương tiền',
            'parent_code'  => null,
            'accounts'     => ['111', '1111', '1112', '1113', '1121', '1122', '1128', '113'],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'TK 112 là TK cha — chỉ lấy chi tiết 1121/1122/1128. '
                             . 'TK 111x là chi tiết của TK 111 (Tiền VN/Ngoại tệ/Vàng). '
                             . 'TK 1281/1288 kỳ hạn ≤3 tháng → tạm xếp vào mã 120.',
        ],

        // ── II. Đầu tư tài chính ngắn hạn (mã 120) ──────────────────────────
        [
            'item_code'    => '120',
            'item_name'    => 'II. Đầu tư tài chính ngắn hạn',
            'parent_code'  => null,
            'accounts'     => ['121', '1281', '1288'],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 228, 2291, 2292.',
        ],

        // ── III. Các khoản phải thu (mã 130 = 131+132+133+134) ───────────────
        // Children phải xuất hiện trước formula 130
        [
            'item_code'    => '131',
            'item_name'    => '1. Phải thu của khách hàng',
            'parent_code'  => '130',
            'accounts'     => ['131', '1311', '1312', '1318'],
            'balance_side' => 'debit_detail',
            'negative'     => false,
            'note'         => 'Dư Nợ chi tiết từng TK; không bù trừ với dư Có.',
        ],
        [
            'item_code'    => '132',
            'item_name'    => '2. Trả trước cho người bán',
            'parent_code'  => '130',
            'accounts'     => ['331', '3311', '3312', '3318', '331UT'],
            'balance_side' => 'debit_detail',
            'negative'     => false,
            'note'         => 'Dư Nợ chi tiết TK 331/331UT = khoản trả trước NCC. 331UT là TK chuẩn cho advance.',
        ],
        [
            'item_code'    => '133',
            'item_name'    => '3. Vốn KD ở đơn vị trực thuộc',
            'parent_code'  => '130',
            'accounts'     => ['1361'],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'Để 0 nếu không có đơn vị hạch toán trực thuộc.',
        ],
        [
            'item_code'    => '134',
            'item_name'    => '4. Phải thu khác',
            'parent_code'  => '130',
            'accounts'     => ['1368', '138', '1386', '1388', '141'],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 1381 (tài sản thiếu chờ XL), 2293 (dự phòng).',
        ],
        [
            'item_code'    => '130',
            'item_name'    => 'III. Các khoản phải thu',
            'parent_code'  => null,
            'accounts'     => [],
            'balance_side' => 'formula',
            'formula'      => '131+132+133+134',
            'negative'     => false,
            'note'         => null,
        ],

        // ── IV. Hàng tồn kho (mã 140) ────────────────────────────────────────
        [
            'item_code'    => '140',
            'item_name'    => 'IV. Hàng tồn kho',
            'parent_code'  => null,
            'accounts'     => [
                '151',
                '152', '1521', '1522', '1523', '1524',
                '153', '1531', '1532',
                '154',
                '156', '1561', '1562',
            ],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 155 (thành phẩm), 157, 2294 (dự phòng HTK).',
        ],

        // ── V. Tài sản cố định (mã 150 = 151_fa + 152_fa) ───────────────────
        [
            'item_code'    => '151_fa',
            'item_name'    => 'Nguyên giá',
            'parent_code'  => '150',
            'accounts'     => ['211', '2111', '213'],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'TK 2111 chi tiết của 211; TK 213 TSCĐ vô hình.',
        ],
        [
            'item_code'    => '152_fa',
            'item_name'    => 'Hao mòn lũy kế (-)',
            'parent_code'  => '150',
            'accounts'     => ['214', '2141', '2142'],
            'balance_side' => 'credit',
            'negative'     => true,
            'note'         => 'TK 214 credit-normal → sumExact trả cr-dr = số dương; negative:true để ghi âm.',
        ],
        [
            'item_code'    => '150',
            'item_name'    => 'V. Tài sản cố định',
            'parent_code'  => null,
            'accounts'     => [],
            'balance_side' => 'formula',
            'formula'      => '151_fa+152_fa',
            'negative'     => false,
            'note'         => null,
        ],

        // ── VI–VII: Bất động sản đầu tư, XDCB dở dang ──────────────────────
        [
            'item_code'    => '160',
            'item_name'    => 'VI. Bất động sản đầu tư',
            'parent_code'  => null,
            'accounts'     => [],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 217, 2147 — để 0.',
        ],
        [
            'item_code'    => '170',
            'item_name'    => 'VII. Chi phí XDCB dở dang',
            'parent_code'  => null,
            'accounts'     => [],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 241 — để 0.',
        ],

        // ── VIII. Tài sản khác (mã 180 = 181+182) ────────────────────────────
        [
            'item_code'    => '181',
            'item_name'    => 'Thuế GTGT được khấu trừ',
            'parent_code'  => '180',
            'accounts'     => ['133', '1331', '1332'],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'Dư Nợ TK 133 → mã 181 (không đưa vào chi phí/giá vốn).',
        ],
        [
            'item_code'    => '182',
            'item_name'    => 'Tài sản khác',
            'parent_code'  => '180',
            'accounts'     => ['242', '2421', '2422'],
            'balance_side' => 'debit',
            'negative'     => false,
            'note'         => 'Chi phí trả trước dài hạn. TK 142 (TT200) không có trong danh mục.',
        ],
        [
            'item_code'    => '180',
            'item_name'    => 'VIII. Tài sản khác',
            'parent_code'  => null,
            'accounts'     => [],
            'balance_side' => 'formula',
            'formula'      => '181+182',
            'negative'     => false,
            'note'         => null,
        ],

        // ── TỔNG TÀI SẢN (mã 200) ────────────────────────────────────────────
        [
            'item_code'    => '200',
            'item_name'    => 'TỔNG CỘNG TÀI SẢN (200)',
            'parent_code'  => null,
            'accounts'     => [],
            'balance_side' => 'formula',
            'formula'      => '110+120+130+140+150+160+170+180',
            'negative'     => false,
            'note'         => null,
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────────
    // PHẦN NGUỒN VỐN  (items xếp: children trước → formulae sau)
    // ─────────────────────────────────────────────────────────────────────────
    'equity_liabilities' => [

        // ── A. Nợ phải trả (mã 300 = 311+...+320) ────────────────────────────
        [
            'item_code'    => '311',
            'item_name'    => '1. Phải trả người bán',
            'parent_code'  => '300',
            'accounts'     => ['331', '3311', '3312', '3318'],
            'balance_side' => 'credit_detail',
            'negative'     => false,
            'note'         => 'Dư Có chi tiết từng TK; không bù trừ với dư Nợ.',
        ],
        [
            'item_code'    => '312',
            'item_name'    => '2. Người mua trả tiền trước',
            'parent_code'  => '300',
            'accounts'     => ['131', '1311', '1312', '1318', '131UT'],
            'balance_side' => 'credit_detail',
            'negative'     => false,
            'note'         => 'Dư Có chi tiết TK 131/131UT = KH đã trả tiền trước.',
        ],
        [
            'item_code'    => '313',
            'item_name'    => '3. Thuế và các khoản phải nộp NN',
            'parent_code'  => '300',
            'accounts'     => ['333', '3331', '3333', '3334', '3335', '3337', '3338', '3339'],
            'balance_side' => 'credit_only',
            'negative'     => false,
            'note'         => 'Chỉ dư Có; dư Nợ (nộp thừa) → đưa vào mã 182 tài sản khác.',
        ],
        [
            'item_code'    => '314',
            'item_name'    => '4. Phải trả người lao động',
            'parent_code'  => '300',
            'accounts'     => ['334', '3341', '3342', '3343', '3344', '3348'],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => null,
        ],
        [
            'item_code'    => '315',
            'item_name'    => '5. Phải trả khác',
            'parent_code'  => '300',
            'accounts'     => [
                // TK 335: BHXH người lao động đóng, chi phí phải trả
                '335',
                '3351', '33511', '33512',
                '3352', '33521', '33522', '33523', '33524', '33525',
                '3353', '33531', '33532', '33533', '33534', '33535',
                '3354', '33541', '33542',
                '3355', '3358',
                // TK 338: Phải trả, phải nộp khác
                '338',
                '3381',
                '3382', '33821', '33822',         // KPCĐ
                '3383', '33831', '33832',          // BHXH
                '3384', '33841', '33842',          // BHYT
                '3385',
                '3386', '33861', '33862',          // BHTN
                '3387',                            // Doanh thu chưa thực hiện
                '3388',
                '33881', '33882', '33883', '33884', '33885',
            ],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Bao gồm KPCĐ (3382), BHXH/BHYT/BHTN (3383/3384/3386), DT chưa TH (3387).',
        ],
        [
            'item_code'    => '316',
            'item_name'    => '6. Vay và nợ thuê tài chính',
            'parent_code'  => '300',
            'accounts'     => ['341', '3411', '3412'],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'TK 3411 Vay dài hạn, TK 3412 Nợ thuê tài chính.',
        ],
        [
            'item_code'    => '317',
            'item_name'    => '7. Phải trả nội bộ về vốn KD',
            'parent_code'  => '300',
            'accounts'     => [],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 3361 — để 0.',
        ],
        [
            'item_code'    => '318',
            'item_name'    => '8. Dự phòng phải trả',
            'parent_code'  => '300',
            'accounts'     => ['352'],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => null,
        ],
        [
            'item_code'    => '319',
            'item_name'    => '9. Quỹ khen thưởng, phúc lợi',
            'parent_code'  => '300',
            'accounts'     => ['353'],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Cảnh báo nếu TK 353 dư Nợ (chi vượt quỹ).',
        ],
        [
            'item_code'    => '320',
            'item_name'    => '10. Quỹ phát triển KH&CN',
            'parent_code'  => '300',
            'accounts'     => [],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 356 — để 0.',
        ],
        [
            'item_code'    => '300',
            'item_name'    => 'A. NỢ PHẢI TRẢ',
            'parent_code'  => null,
            'accounts'     => [],
            'balance_side' => 'formula',
            'formula'      => '311+312+313+314+315+316+317+318+319+320',
            'negative'     => false,
            'note'         => null,
        ],

        // ── B. Vốn chủ sở hữu (mã 400 = 411+...+417) ────────────────────────
        [
            'item_code'    => '411',
            'item_name'    => '1. Vốn góp của chủ sở hữu',
            'parent_code'  => '400',
            'accounts'     => ['411', '4111'],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Chỉ cộng 1 trong 2 — nếu cả 2 có số dư sẽ cảnh báo double-count.',
        ],
        [
            'item_code'    => '412',
            'item_name'    => '2. Thặng dư vốn cổ phần',
            'parent_code'  => '400',
            'accounts'     => [],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 4112 — để 0.',
        ],
        [
            'item_code'    => '413',
            'item_name'    => '3. Vốn khác của CSH',
            'parent_code'  => '400',
            'accounts'     => [],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 4118 — để 0.',
        ],
        [
            'item_code'    => '414',
            'item_name'    => '4. Cổ phiếu quỹ (-)',
            'parent_code'  => '400',
            'accounts'     => [],
            'balance_side' => 'debit',
            'negative'     => true,
            'note'         => 'Danh mục chưa có TK 419 — để 0.',
        ],
        [
            'item_code'    => '415',
            'item_name'    => '5. Chênh lệch tỷ giá',
            'parent_code'  => '400',
            'accounts'     => [],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Để 0 nếu không giao dịch ngoại tệ.',
        ],
        [
            'item_code'    => '416',
            'item_name'    => '6. Các quỹ thuộc VCSH',
            'parent_code'  => '400',
            'accounts'     => [],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Danh mục chưa có TK 418 — để 0.',
        ],
        [
            'item_code'    => '417',
            'item_name'    => '7. LNST chưa phân phối',
            'parent_code'  => '400',
            'accounts'     => ['421', '4211', '4212'],
            'balance_side' => 'credit',
            'negative'     => false,
            'note'         => 'Bắt buộc lấy TK 421. TK 4211 = LNST năm trước, TK 4212 = LNST năm nay. Cho phép âm (lỗ lũy kế).',
        ],
        [
            'item_code'    => '400',
            'item_name'    => 'B. VỐN CHỦ SỞ HỮU',
            'parent_code'  => null,
            'accounts'     => [],
            'balance_side' => 'formula',
            'formula'      => '411+412+413+414+415+416+417',
            'negative'     => false,
            'note'         => null,
        ],

        // ── TỔNG NGUỒN VỐN (mã 500) ──────────────────────────────────────────
        [
            'item_code'    => '500',
            'item_name'    => 'TỔNG CỘNG NGUỒN VỐN (500)',
            'parent_code'  => null,
            'accounts'     => [],
            'balance_side' => 'formula',
            'formula'      => '300+400',
            'negative'     => false,
            'note'         => null,
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────────
    // Tài khoản có trong danh mục nhưng chưa được mapping → cảnh báo
    // ─────────────────────────────────────────────────────────────────────────
    'unmapped_account_warning' => [
        // Accounts that exist in the system but aren't in any report line
        // (auto-detected at runtime — this list is supplemental documentation only)
    ],

    // Tài khoản thiếu trong danh mục hệ thống nhưng cần cho B01a-DNN
    'missing_from_chart' => [
        '1381' => 'Tài sản thiếu chờ xử lý (mã 135)',
        '2293' => 'Dự phòng phải thu khó đòi (mã 136)',
        '155'  => 'Thành phẩm (mã 140)',
        '157'  => 'Hàng gửi bán (mã 140)',
        '2294' => 'Dự phòng giảm giá HTK (mã 149)',
        '217'  => 'Bất động sản đầu tư (mã 160)',
        '2147' => 'Hao mòn BĐS đầu tư (mã 160)',
        '241'  => 'XDCB dở dang (mã 170)',
        '4112' => 'Thặng dư vốn cổ phần (mã 412)',
        '4118' => 'Vốn khác của CSH (mã 413)',
        '419'  => 'Cổ phiếu quỹ (mã 414)',
        '418'  => 'Quỹ thuộc VCSH (mã 416)',
        '356'  => 'Quỹ phát triển KH&CN (mã 320)',
        '3361' => 'Phải trả nội bộ về vốn KD (mã 317)',
        '228'  => 'Đầu tư góp vốn (mã 120)',
        '2291' => 'Dự phòng tổn thất đầu tư (mã 120)',
    ],

    // TK chỉ dùng tổng hợp — không nên hạch toán trực tiếp
    'summary_only_accounts' => ['511', '635', '642', '821'],
];
