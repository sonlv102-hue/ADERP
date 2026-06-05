<?php

namespace Database\Seeders;

use App\Models\AccountCode;
use Illuminate\Database\Seeder;

/**
 * Hệ thống tài khoản kế toán Việt Nam theo Thông tư 133/2016/TT-BTC
 * Nguồn: file "Danh mục TKKT.xlsx" của doanh nghiệp
 */
class AccountCodeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as $acc) {
            AccountCode::updateOrCreate(['code' => $acc['code']], $acc);
        }

        // Deactivate các TK không còn dùng (không có trong danh mục chính thức)
        AccountCode::whereIn('code', $this->deactivated())->update(['is_active' => false]);
    }

    private function accounts(): array
    {
        // [code, name, type, normal_balance, parent_code, level, is_detail]
        $raw = [
            // ════════════════════════════════════════════════════════════
            // LOẠI 1 — TÀI SẢN
            // ════════════════════════════════════════════════════════════
            ['1',    'Tài sản ngắn hạn',                                   'asset',     'debit',  null,  1, false],

            // ── Tiền ──
            ['11',   'Tiền',                                                'asset',     'debit',  '1',   2, false],
            ['111',  'Tiền mặt',                                            'asset',     'debit',  '11',  3, false],
            ['1111', 'Tiền Việt Nam',                                       'asset',     'debit',  '111', 4, true],
            ['1112', 'Ngoại tệ',                                            'asset',     'debit',  '111', 4, true],
            ['112',  'Tiền gửi ngân hàng',                                  'asset',     'debit',  '11',  3, false],
            ['1121', 'Tiền Việt Nam',                                       'asset',     'debit',  '112', 4, true],
            ['1122', 'Ngoại tệ',                                            'asset',     'debit',  '112', 4, true],

            // ── Đầu tư tài chính ngắn hạn ──
            ['12',   'Đầu tư tài chính ngắn hạn',                          'asset',     'debit',  '1',   2, false],
            ['121',  'Chứng khoán kinh doanh',                              'asset',     'debit',  '12',  3, true],
            ['128',  'Đầu tư nắm giữ đến ngày đáo hạn',                    'asset',     'debit',  '12',  3, false],
            ['1281', 'Tiền gửi có kỳ hạn',                                  'asset',     'debit',  '128', 4, true],
            ['1288', 'Các khoản đầu tư khác nắm giữ đến ngày đáo hạn',     'asset',     'debit',  '128', 4, true],

            // ── Phải thu ──
            ['13',   'Các khoản phải thu',                                  'asset',     'debit',  '1',   2, false],
            ['131',  'Phải thu của khách hàng',                             'asset',     'debit',  '13',  3, true],
            ['133',  'Thuế GTGT được khấu trừ',                             'asset',     'debit',  '13',  3, false],
            ['1331', 'Thuế GTGT được khấu trừ của hàng hóa, dịch vụ',      'asset',     'debit',  '133', 4, true],
            ['1332', 'Thuế GTGT được khấu trừ của TSCĐ',                   'asset',     'debit',  '133', 4, true],
            ['136',  'Phải thu nội bộ',                                     'asset',     'debit',  '13',  3, false],
            ['1361', 'Vốn kinh doanh ở đơn vị trực thuộc',                 'asset',     'debit',  '136', 4, true],
            ['1368', 'Phải thu nội bộ khác',                                'asset',     'debit',  '136', 4, true],
            ['138',  'Phải thu khác',                                       'asset',     'debit',  '13',  3, false],
            ['1381', 'Tài sản thiếu chờ xử lý',                            'asset',     'debit',  '138', 4, true],
            ['1386', 'Cầm cố, thế chấp, ký quỹ, ký cược',                  'asset',     'debit',  '138', 4, true],
            ['1388', 'Phải thu khác',                                       'asset',     'debit',  '138', 4, true],

            // ── Tạm ứng ──
            ['14',   'Tạm ứng',                                             'asset',     'debit',  '1',   2, false],
            ['141',  'Tạm ứng',                                             'asset',     'debit',  '14',  3, true],

            // ── Hàng tồn kho ──
            ['15',   'Hàng tồn kho',                                        'asset',     'debit',  '1',   2, false],
            ['151',  'Hàng mua đang đi đường',                              'asset',     'debit',  '15',  3, true],
            ['152',  'Nguyên liệu, vật liệu',                               'asset',     'debit',  '15',  3, true],
            ['153',  'Công cụ, dụng cụ',                                    'asset',     'debit',  '15',  3, true],
            ['154',  'Chi phí sản xuất kinh doanh dở dang',                 'asset',     'debit',  '15',  3, true],
            ['155',  'Thành phẩm',                                          'asset',     'debit',  '15',  3, true],
            ['156',  'Hàng hóa',                                            'asset',     'debit',  '15',  3, true],
            ['157',  'Hàng gửi đi bán',                                     'asset',     'debit',  '15',  3, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 2 — TÀI SẢN DÀI HẠN
            // ════════════════════════════════════════════════════════════
            ['2',    'Tài sản dài hạn',                                     'asset',     'debit',  null,  1, false],

            // ── Tài sản cố định ──
            ['21',   'Tài sản cố định',                                     'asset',     'debit',  '2',   2, false],
            ['211',  'Tài sản cố định',                                     'asset',     'debit',  '21',  3, false],
            ['2111', 'TSCĐ hữu hình',                                       'asset',     'debit',  '211', 4, true],
            ['2112', 'TSCĐ thuê tài chính',                                 'asset',     'debit',  '211', 4, true],
            ['2113', 'TSCĐ vô hình',                                        'asset',     'debit',  '211', 4, true],
            ['214',  'Hao mòn TSCĐ',                                        'contra',    'credit', '21',  3, false],
            ['2141', 'Hao mòn TSCĐ hữu hình',                              'contra',    'credit', '214', 4, true],
            ['2142', 'Hao mòn TSCĐ thuê tài chính',                        'contra',    'credit', '214', 4, true],
            ['2143', 'Hao mòn TSCĐ vô hình',                               'contra',    'credit', '214', 4, true],
            ['2147', 'Hao mòn bất động sản đầu tư',                        'contra',    'credit', '214', 4, true],
            ['217',  'Bất động sản đầu tư',                                 'asset',     'debit',  '21',  3, true],

            // ── Đầu tư tài chính dài hạn ──
            ['22',   'Đầu tư tài chính dài hạn',                           'asset',     'debit',  '2',   2, false],
            ['228',  'Đầu tư góp vốn vào đơn vị khác',                     'asset',     'debit',  '22',  3, false],
            ['2281', 'Đầu tư vào công ty liên doanh, liên kết',             'asset',     'debit',  '228', 4, true],
            ['2288', 'Đầu tư khác',                                         'asset',     'debit',  '228', 4, true],
            ['229',  'Dự phòng tổn thất tài sản',                           'contra',    'credit', '22',  3, false],
            ['2291', 'Dự phòng giảm giá chứng khoán kinh doanh',           'contra',    'credit', '229', 4, true],
            ['2292', 'Dự phòng tổn thất đầu tư vào đơn vị khác',          'contra',    'credit', '229', 4, true],
            ['2293', 'Dự phòng phải thu khó đòi',                          'contra',    'credit', '229', 4, true],
            ['2294', 'Dự phòng giảm giá hàng tồn kho',                     'contra',    'credit', '229', 4, true],

            // ── Tài sản dài hạn khác ──
            ['24',   'Tài sản dài hạn khác',                                'asset',     'debit',  '2',   2, false],
            ['241',  'Xây dựng cơ bản dở dang',                             'asset',     'debit',  '24',  3, false],
            ['2411', 'Mua sắm TSCĐ',                                        'asset',     'debit',  '241', 4, true],
            ['2412', 'Xây dựng cơ bản',                                     'asset',     'debit',  '241', 4, true],
            ['2413', 'Sửa chữa lớn TSCĐ',                                  'asset',     'debit',  '241', 4, true],
            ['242',  'Chi phí trả trước',                                   'asset',     'debit',  '24',  3, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 3 — NỢ PHẢI TRẢ
            // ════════════════════════════════════════════════════════════
            ['3',    'Nợ phải trả',                                         'liability', 'credit', null,  1, false],
            ['33',   'Phải trả người bán và các khoản phải trả',            'liability', 'credit', '3',   2, false],
            ['331',  'Phải trả cho người bán',                              'liability', 'credit', '33',  3, true],
            ['333',  'Thuế và các khoản phải nộp Nhà nước',                'liability', 'credit', '33',  3, false],
            ['3331', 'Thuế GTGT phải nộp',                                  'liability', 'credit', '333', 4, false],
            ['33311','Thuế GTGT đầu ra',                                    'liability', 'credit', '3331',5, true],
            ['33312','Thuế GTGT hàng nhập khẩu',                           'liability', 'credit', '3331',5, true],
            ['3332', 'Thuế tiêu thụ đặc biệt',                              'liability', 'credit', '333', 4, true],
            ['3333', 'Thuế xuất nhập khẩu',                                 'liability', 'credit', '333', 4, true],
            ['3334', 'Thuế thu nhập doanh nghiệp',                          'liability', 'credit', '333', 4, true],
            ['3335', 'Thuế thu nhập cá nhân',                               'liability', 'credit', '333', 4, true],
            ['3336', 'Thuế tài nguyên',                                     'liability', 'credit', '333', 4, true],
            ['3337', 'Thuế nhà đất, tiền thuê đất',                         'liability', 'credit', '333', 4, true],
            ['3338', 'Thuế bảo vệ môi trường và các loại thuế khác',       'liability', 'credit', '333', 4, false],
            ['33381','Thuế bảo vệ môi trường',                              'liability', 'credit', '3338',5, true],
            ['33382','Các loại thuế khác',                                  'liability', 'credit', '3338',5, true],
            ['3339', 'Phí, lệ phí và các khoản phải nộp khác',             'liability', 'credit', '333', 4, true],
            ['334',  'Phải trả người lao động',                             'liability', 'credit', '33',  3, true],
            ['335',  'Chi phí phải trả',                                    'liability', 'credit', '33',  3, true],
            ['336',  'Phải trả nội bộ',                                     'liability', 'credit', '33',  3, false],
            ['3361', 'Phải trả nội bộ về vốn kinh doanh',                  'liability', 'credit', '336', 4, true],
            ['3368', 'Phải trả nội bộ khác',                                'liability', 'credit', '336', 4, true],
            ['338',  'Phải trả, phải nộp khác',                             'liability', 'credit', '33',  3, false],
            ['3381', 'Tài sản thừa chờ giải quyết',                        'liability', 'credit', '338', 4, true],
            ['3382', 'Kinh phí công đoàn',                                  'liability', 'credit', '338', 4, true],
            ['3383', 'Bảo hiểm xã hội',                                     'liability', 'credit', '338', 4, true],
            ['3384', 'Bảo hiểm y tế',                                       'liability', 'credit', '338', 4, true],
            ['3385', 'Bảo hiểm thất nghiệp',                                'liability', 'credit', '338', 4, true],
            ['3386', 'Nhận ký quỹ, ký cược',                                'liability', 'credit', '338', 4, true],
            ['3387', 'Doanh thu chưa thực hiện',                            'liability', 'credit', '338', 4, true],
            ['3388', 'Phải trả, phải nộp khác',                             'liability', 'credit', '338', 4, true],

            ['34',   'Nợ dài hạn',                                          'liability', 'credit', '3',   2, false],
            ['341',  'Vay và nợ thuê tài chính',                            'liability', 'credit', '34',  3, false],
            ['3411', 'Các khoản đi vay',                                    'liability', 'credit', '341', 4, true],
            ['3412', 'Nợ thuê tài chính',                                   'liability', 'credit', '341', 4, true],
            ['352',  'Dự phòng phải trả',                                   'liability', 'credit', '34',  3, false],
            ['3521', 'Dự phòng bảo hành sản phẩm hàng hóa',                'liability', 'credit', '352', 4, true],
            ['3522', 'Dự phòng bảo hành công trình xây dựng',              'liability', 'credit', '352', 4, true],
            ['3524', 'Dự phòng phải trả khác',                              'liability', 'credit', '352', 4, true],
            ['353',  'Quỹ khen thưởng phúc lợi',                            'liability', 'credit', '34',  3, false],
            ['3531', 'Quỹ khen thưởng',                                     'liability', 'credit', '353', 4, true],
            ['3532', 'Quỹ phúc lợi',                                        'liability', 'credit', '353', 4, true],
            ['3533', 'Quỹ phúc lợi đã hình thành TSCĐ',                    'liability', 'credit', '353', 4, true],
            ['3534', 'Quỹ thưởng ban quản lý điều hành công ty',            'liability', 'credit', '353', 4, true],
            ['356',  'Quỹ phát triển khoa học và công nghệ',                'liability', 'credit', '34',  3, false],
            ['3561', 'Quỹ phát triển khoa học và công nghệ',                'liability', 'credit', '356', 4, true],
            ['3562', 'Quỹ phát triển khoa học và công nghệ đã hình thành TSCĐ', 'liability', 'credit', '356', 4, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 4 — VỐN CHỦ SỞ HỮU
            // ════════════════════════════════════════════════════════════
            ['4',    'Vốn chủ sở hữu',                                      'equity',    'credit', null,  1, false],
            ['41',   'Vốn chủ sở hữu',                                      'equity',    'credit', '4',   2, false],
            ['411',  'Vốn đầu tư của chủ sở hữu',                          'equity',    'credit', '41',  3, false],
            ['4111', 'Vốn đầu tư của chủ sở hữu',                          'equity',    'credit', '411', 4, true],
            ['4112', 'Thặng dư vốn cổ phần',                               'equity',    'credit', '411', 4, true],
            ['4118', 'Vốn khác',                                            'equity',    'credit', '411', 4, true],
            ['413',  'Chênh lệch tỷ giá hối đoái',                         'equity',    'credit', '41',  3, true],
            ['418',  'Các quỹ thuộc vốn chủ sở hữu',                       'equity',    'credit', '41',  3, true],
            ['419',  'Cổ phiếu quỹ',                                        'equity',    'debit',  '41',  3, true],
            ['421',  'Lợi nhuận sau thuế chưa phân phối',                   'equity',    'credit', '41',  3, false],
            ['4211', 'Lợi nhuận chưa phân phối năm trước',                  'equity',    'credit', '421', 4, true],
            ['4212', 'Lợi nhuận chưa phân phối năm nay',                    'equity',    'credit', '421', 4, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 5 — DOANH THU
            // ════════════════════════════════════════════════════════════
            ['5',    'Doanh thu',                                            'revenue',   'credit', null,  1, false],
            ['51',   'Doanh thu bán hàng và cung cấp dịch vụ',              'revenue',   'credit', '5',   2, false],
            ['511',  'Doanh thu bán hàng và cung cấp dịch vụ',              'revenue',   'credit', '51',  3, false],
            ['5111', 'Doanh thu bán hàng hóa',                              'revenue',   'credit', '511', 4, true],
            ['5112', 'Doanh thu bán thành phẩm',                            'revenue',   'credit', '511', 4, true],
            ['5113', 'Doanh thu cung cấp dịch vụ',                          'revenue',   'credit', '511', 4, true],
            ['5118', 'Doanh thu khác',                                       'revenue',   'credit', '511', 4, true],
            ['515',  'Doanh thu hoạt động tài chính',                       'revenue',   'credit', '51',  3, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 6 — CHI PHÍ
            // ════════════════════════════════════════════════════════════
            ['6',    'Chi phí sản xuất kinh doanh',                         'expense',   'debit',  null,  1, false],
            ['61',   'Giá vốn và chi phí sản xuất',                         'expense',   'debit',  '6',   2, false],
            ['611',  'Mua hàng',                                             'expense',   'debit',  '61',  3, true],
            ['631',  'Giá thành sản xuất',                                  'expense',   'debit',  '61',  3, true],
            ['632',  'Giá vốn hàng bán',                                    'expense',   'debit',  '61',  3, true],
            ['635',  'Chi phí tài chính',                                   'expense',   'debit',  '61',  3, true],
            ['642',  'Chi phí quản lý kinh doanh',                          'expense',   'debit',  '6',   2, false],
            ['6421', 'Chi phí bán hàng',                                    'expense',   'debit',  '642', 3, true],
            ['6422', 'Chi phí quản lý doanh nghiệp',                        'expense',   'debit',  '642', 3, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 7, 8, 9
            // ════════════════════════════════════════════════════════════
            ['7',    'Thu nhập khác',                                        'revenue',   'credit', null,  1, false],
            ['711',  'Thu nhập khác',                                        'revenue',   'credit', '7',   2, true],

            ['8',    'Chi phí khác',                                         'expense',   'debit',  null,  1, false],
            ['811',  'Chi phí khác',                                         'expense',   'debit',  '8',   2, true],
            ['821',  'Chi phí thuế thu nhập doanh nghiệp',                  'expense',   'debit',  '8',   2, true],

            ['9',    'Xác định kết quả kinh doanh',                          'equity',    'credit', null,  1, false],
            ['911',  'Xác định kết quả kinh doanh',                          'equity',    'credit', '9',   2, true],
        ];

        return array_map(fn ($r) => [
            'code'           => $r[0],
            'name'           => $r[1],
            'type'           => $r[2],
            'normal_balance' => $r[3],
            'parent_code'    => $r[4],
            'level'          => $r[5],
            'is_detail'      => $r[6],
            'is_active'      => true,
        ], $raw);
    }

    private function deactivated(): array
    {
        return [
            // Không có trong file TT133 của doanh nghiệp
            '113', '1131', '1132',          // tiền đang chuyển
            '139',                           // dự phòng phải thu (→ 2293)
            '158',                           // kho bảo thuế
            '161', '1611', '1612',          // chi sự nghiệp
            '1282', '1283',                 // đầu tư khác nắm giữ (không dùng)
            '1385',                          // phải thu cổ phần hóa
            '1531', '1532', '1533',         // chi tiết CCDC
            '1551', '1557',                 // chi tiết thành phẩm
            '1561', '1562', '1567',         // chi tiết hàng hóa
            '212', '213',                   // → dùng 2112, 2113 dưới 211
            '2114', '2115', '2118',         // chi tiết TSCĐ hữu hình
            '2121', '2122', '2123', '2124', '2128', // chi tiết TSCĐ thuê TC
            '2131', '2132', '2133', '2134', '2135', '2136', '2138', // TSCĐ vô hình
            '221', '222',                   // đầu tư công ty con/liên kết riêng
            '243', '244',                   // tài sản thuế hoãn lại, ký quỹ DH
            '24x',                          // fake group code cũ
            '311', '315',                   // vay ngắn hạn riêng
            '337',                          // thanh toán tiến độ xây dựng
            '3389',                         // BHTN (→ 3385)
            '344', '347', '357',            // ký quỹ DH, thuế hoãn lại, quỹ bình ổn
            '412', '414', '417',            // chênh lệch ĐG, quỹ đầu tư, quỹ hỗ trợ
            '4113',                          // → 4118
            '441', '461', '4611', '4612', '466', // nguồn vốn XDCB, kinh phí
            '5114', '5117',                 // doanh thu trợ cấp, BĐS
            '521', '5211', '5212', '5213',  // giảm trừ doanh thu
            '512',                           // bán hàng nội bộ
            '6111', '6112',                 // chi tiết mua hàng
            '621', '622', '623', '627',     // chi phí SX
            '641', '6411', '6412', '6413', '6414', '6415', '6417', '6418', // chi phí bán hàng riêng
            '6423', '6424', '6425', '6426', '6427', '6428', // chi tiết 642 cũ
            '8211', '8212',                 // chi tiết TNDN
            '332', '129', '159', '215', '343', // TT200 cũ
        ];
    }
}
