<?php

namespace Database\Seeders;

use App\Models\AccountCode;
use Illuminate\Database\Seeder;

/**
 * Hệ thống tài khoản kế toán Việt Nam theo Thông tư 200/2014/TT-BTC
 * Áp dụng cho doanh nghiệp thương mại & dịch vụ CNTT
 */
class AccountCodeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as $acc) {
            AccountCode::updateOrCreate(['code' => $acc['code']], $acc);
        }
    }

    private function accounts(): array
    {
        // [code, name, type, normal_balance, parent_code, level, is_detail]
        $raw = [
            // ════════════════════════════════════════════════════════════
            // LOẠI 1 — TÀI SẢN NGẮN HẠN
            // ════════════════════════════════════════════════════════════
            ['1', 'Tiền và các khoản tương đương tiền', 'asset', 'debit', null, 1, false],
            ['11', 'Tiền', 'asset', 'debit', '1', 2, false],
            ['111', 'Tiền mặt', 'asset', 'debit', '11', 3, true],
            ['112', 'Tiền gửi ngân hàng', 'asset', 'debit', '11', 3, true],
            ['113', 'Tiền đang chuyển', 'asset', 'debit', '11', 3, true],

            ['12', 'Đầu tư tài chính ngắn hạn', 'asset', 'debit', '1', 2, false],
            ['121', 'Chứng khoán kinh doanh', 'asset', 'debit', '12', 3, true],
            ['128', 'Đầu tư nắm giữ đến ngày đáo hạn', 'asset', 'debit', '12', 3, true],
            ['129', 'Dự phòng tổn thất đầu tư tài chính', 'asset', 'credit', '12', 3, true],

            ['13', 'Các khoản phải thu ngắn hạn', 'asset', 'debit', '1', 2, false],
            ['131', 'Phải thu của khách hàng', 'asset', 'debit', '13', 3, true],
            ['133', 'Thuế GTGT được khấu trừ', 'asset', 'debit', '13', 3, false],
            ['1331', 'Thuế GTGT hàng hóa, dịch vụ', 'asset', 'debit', '133', 4, true],
            ['1332', 'Thuế GTGT tài sản cố định', 'asset', 'debit', '133', 4, true],
            ['136', 'Phải thu nội bộ ngắn hạn', 'asset', 'debit', '13', 3, true],
            ['138', 'Phải thu khác', 'asset', 'debit', '13', 3, true],
            ['139', 'Dự phòng phải thu ngắn hạn khó đòi', 'asset', 'credit', '13', 3, true],

            ['14', 'Tạm ứng', 'asset', 'debit', '1', 2, false],
            ['141', 'Tạm ứng', 'asset', 'debit', '14', 3, true],

            ['15', 'Hàng tồn kho', 'asset', 'debit', '1', 2, false],
            ['151', 'Hàng mua đang đi đường', 'asset', 'debit', '15', 3, true],
            ['152', 'Nguyên liệu, vật liệu', 'asset', 'debit', '15', 3, true],
            ['153', 'Công cụ, dụng cụ', 'asset', 'debit', '15', 3, true],
            ['154', 'Chi phí sản xuất kinh doanh dở dang', 'asset', 'debit', '15', 3, true],
            ['155', 'Thành phẩm', 'asset', 'debit', '15', 3, true],
            ['156', 'Hàng hóa', 'asset', 'debit', '15', 3, true],
            ['157', 'Hàng gửi đi bán', 'asset', 'debit', '15', 3, true],
            ['158', 'Hàng hóa kho bảo thuế', 'asset', 'debit', '15', 3, true],
            ['159', 'Dự phòng giảm giá hàng tồn kho', 'asset', 'credit', '15', 3, true],

            ['16', 'Tài sản ngắn hạn khác', 'asset', 'debit', '1', 2, false],
            ['161', 'Chi sự nghiệp', 'asset', 'debit', '16', 3, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 2 — TÀI SẢN DÀI HẠN
            // ════════════════════════════════════════════════════════════
            ['2', 'Tài sản dài hạn', 'asset', 'debit', null, 1, false],
            ['21', 'Tài sản cố định', 'asset', 'debit', '2', 2, false],
            ['211', 'Tài sản cố định hữu hình', 'asset', 'debit', '21', 3, true],
            ['212', 'Tài sản cố định thuê tài chính', 'asset', 'debit', '21', 3, true],
            ['213', 'Tài sản cố định vô hình', 'asset', 'debit', '21', 3, true],
            ['214', 'Hao mòn tài sản cố định', 'contra', 'credit', '21', 3, true],
            ['215', 'Tài sản cố định thuê tài chính - hao mòn', 'contra', 'credit', '21', 3, true],

            ['22', 'Đầu tư tài chính dài hạn', 'asset', 'debit', '2', 2, false],
            ['221', 'Đầu tư vào công ty con', 'asset', 'debit', '22', 3, true],
            ['222', 'Đầu tư vào công ty liên kết', 'asset', 'debit', '22', 3, true],
            ['228', 'Đầu tư khác', 'asset', 'debit', '22', 3, true],
            ['229', 'Dự phòng tổn thất đầu tư tài chính dài hạn', 'asset', 'credit', '22', 3, true],

            ['24', 'Bất động sản đầu tư', 'asset', 'debit', '2', 2, false],
            ['217', 'Bất động sản đầu tư', 'asset', 'debit', '24', 3, true],
            ['2147', 'Hao mòn bất động sản đầu tư', 'contra', 'credit', '24', 3, true],

            ['24x', 'Tài sản dài hạn khác', 'asset', 'debit', '2', 2, false],
            ['241', 'Xây dựng cơ bản dở dang', 'asset', 'debit', '24x', 3, true],
            ['242', 'Chi phí trả trước dài hạn', 'asset', 'debit', '24x', 3, true],
            ['243', 'Tài sản thuế thu nhập hoãn lại', 'asset', 'debit', '24x', 3, true],
            ['244', 'Cầm cố, thế chấp, ký quỹ, ký cược', 'asset', 'debit', '24x', 3, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 3 — NỢ PHẢI TRẢ
            // ════════════════════════════════════════════════════════════
            ['3', 'Nợ phải trả', 'liability', 'credit', null, 1, false],
            ['31', 'Vay và nợ thuê tài chính', 'liability', 'credit', '3', 2, false],
            ['311', 'Vay ngắn hạn', 'liability', 'credit', '31', 3, true],
            ['315', 'Nợ dài hạn đến hạn trả', 'liability', 'credit', '31', 3, true],
            ['341', 'Vay và nợ thuê tài chính dài hạn', 'liability', 'credit', '31', 3, true],
            ['343', 'Trái phiếu phát hành', 'liability', 'credit', '31', 3, true],

            ['33', 'Phải trả người bán và các khoản phải trả', 'liability', 'credit', '3', 2, false],
            ['331', 'Phải trả người bán', 'liability', 'credit', '33', 3, true],
            ['332', 'Thuế và các khoản phải nộp nhà nước', 'liability', 'credit', '33', 3, false],
            ['3331', 'Thuế GTGT phải nộp', 'liability', 'credit', '332', 4, false],
            ['33311', 'Thuế GTGT đầu ra', 'liability', 'credit', '3331', 5, true],
            ['33312', 'Thuế GTGT hàng nhập khẩu', 'liability', 'credit', '3331', 5, true],
            ['3332', 'Thuế tiêu thụ đặc biệt', 'liability', 'credit', '332', 4, true],
            ['3333', 'Thuế xuất, nhập khẩu', 'liability', 'credit', '332', 4, true],
            ['3334', 'Thuế thu nhập doanh nghiệp', 'liability', 'credit', '332', 4, true],
            ['3335', 'Thuế thu nhập cá nhân', 'liability', 'credit', '332', 4, true],
            ['3336', 'Thuế tài nguyên', 'liability', 'credit', '332', 4, true],
            ['3337', 'Thuế nhà đất, tiền thuê đất', 'liability', 'credit', '332', 4, true],
            ['3338', 'Các loại thuế khác', 'liability', 'credit', '332', 4, true],
            ['3339', 'Phí, lệ phí và các khoản phải nộp khác', 'liability', 'credit', '332', 4, true],
            ['334', 'Phải trả người lao động', 'liability', 'credit', '33', 3, true],
            ['335', 'Chi phí phải trả', 'liability', 'credit', '33', 3, true],
            ['336', 'Phải trả nội bộ', 'liability', 'credit', '33', 3, true],
            ['337', 'Thanh toán theo tiến độ kế hoạch HĐ xây dựng', 'liability', 'credit', '33', 3, true],
            ['338', 'Phải trả, phải nộp khác', 'liability', 'credit', '33', 3, false],
            ['3381', 'Tài sản thừa chờ giải quyết', 'liability', 'credit', '338', 4, true],
            ['3382', 'Kinh phí công đoàn', 'liability', 'credit', '338', 4, true],
            ['3383', 'Bảo hiểm xã hội', 'liability', 'credit', '338', 4, true],
            ['3384', 'Bảo hiểm y tế', 'liability', 'credit', '338', 4, true],
            ['3385', 'Phải trả về cổ phần hóa', 'liability', 'credit', '338', 4, true],
            ['3386', 'Nhận ký quỹ, ký cược ngắn hạn', 'liability', 'credit', '338', 4, true],
            ['3387', 'Doanh thu chưa thực hiện', 'liability', 'credit', '338', 4, true],
            ['3388', 'Phải trả, phải nộp khác', 'liability', 'credit', '338', 4, true],
            ['3389', 'Bảo hiểm thất nghiệp', 'liability', 'credit', '338', 4, true],

            ['34', 'Nợ dài hạn khác', 'liability', 'credit', '3', 2, false],
            ['344', 'Nhận ký quỹ, ký cược dài hạn', 'liability', 'credit', '34', 3, true],
            ['347', 'Thuế thu nhập hoãn lại phải trả', 'liability', 'credit', '34', 3, true],
            ['352', 'Dự phòng phải trả', 'liability', 'credit', '34', 3, true],
            ['353', 'Quỹ khen thưởng, phúc lợi', 'liability', 'credit', '34', 3, true],
            ['356', 'Quỹ phát triển khoa học và công nghệ', 'liability', 'credit', '34', 3, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 4 — VỐN CHỦ SỞ HỮU
            // ════════════════════════════════════════════════════════════
            ['4', 'Vốn chủ sở hữu', 'equity', 'credit', null, 1, false],
            ['41', 'Vốn chủ sở hữu', 'equity', 'credit', '4', 2, false],
            ['411', 'Vốn đầu tư của chủ sở hữu', 'equity', 'credit', '41', 3, false],
            ['4111', 'Vốn góp của chủ sở hữu', 'equity', 'credit', '411', 4, true],
            ['4112', 'Thặng dư vốn cổ phần', 'equity', 'credit', '411', 4, true],
            ['4113', 'Vốn khác', 'equity', 'credit', '411', 4, true],
            ['412', 'Chênh lệch đánh giá lại tài sản', 'equity', 'credit', '41', 3, true],
            ['413', 'Chênh lệch tỷ giá hối đoái', 'equity', 'credit', '41', 3, true],
            ['414', 'Quỹ đầu tư phát triển', 'equity', 'credit', '41', 3, true],
            ['417', 'Quỹ hỗ trợ sắp xếp doanh nghiệp', 'equity', 'credit', '41', 3, true],
            ['418', 'Các quỹ khác thuộc vốn chủ sở hữu', 'equity', 'credit', '41', 3, true],
            ['419', 'Cổ phiếu quỹ', 'equity', 'debit', '41', 3, true],
            ['421', 'Lợi nhuận sau thuế chưa phân phối', 'equity', 'credit', '41', 3, false],
            ['4211', 'Lợi nhuận sau thuế chưa phân phối năm trước', 'equity', 'credit', '421', 4, true],
            ['4212', 'Lợi nhuận sau thuế chưa phân phối năm nay', 'equity', 'credit', '421', 4, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 5 — DOANH THU
            // ════════════════════════════════════════════════════════════
            ['5', 'Doanh thu', 'revenue', 'credit', null, 1, false],
            ['51', 'Doanh thu bán hàng và cung cấp dịch vụ', 'revenue', 'credit', '5', 2, false],
            ['511', 'Doanh thu bán hàng và cung cấp dịch vụ', 'revenue', 'credit', '51', 3, false],
            ['5111', 'Doanh thu bán hàng hóa', 'revenue', 'credit', '511', 4, true],
            ['5112', 'Doanh thu bán các thành phẩm', 'revenue', 'credit', '511', 4, true],
            ['5113', 'Doanh thu cung cấp dịch vụ', 'revenue', 'credit', '511', 4, true],
            ['5114', 'Doanh thu trợ cấp, trợ giá', 'revenue', 'credit', '511', 4, true],
            ['5117', 'Doanh thu kinh doanh bất động sản đầu tư', 'revenue', 'credit', '511', 4, true],
            ['512', 'Doanh thu bán hàng nội bộ', 'revenue', 'credit', '51', 3, true],
            ['515', 'Doanh thu hoạt động tài chính', 'revenue', 'credit', '51', 3, true],
            ['521', 'Các khoản giảm trừ doanh thu', 'contra', 'debit', '5', 2, false],
            ['5211', 'Chiết khấu thương mại', 'contra', 'debit', '521', 3, true],
            ['5212', 'Hàng bán bị trả lại', 'contra', 'debit', '521', 3, true],
            ['5213', 'Giảm giá hàng bán', 'contra', 'debit', '521', 3, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 6 — CHI PHÍ SẢN XUẤT KINH DOANH
            // ════════════════════════════════════════════════════════════
            ['6', 'Chi phí sản xuất kinh doanh', 'expense', 'debit', null, 1, false],
            ['61', 'Giá vốn và chi phí mua hàng', 'expense', 'debit', '6', 2, false],
            ['631', 'Giá thành sản xuất', 'expense', 'debit', '61', 3, true],
            ['632', 'Giá vốn hàng bán', 'expense', 'debit', '61', 3, true],
            ['635', 'Chi phí tài chính', 'expense', 'debit', '61', 3, true],
            ['641', 'Chi phí bán hàng', 'expense', 'debit', '6', 2, true],
            ['642', 'Chi phí quản lý doanh nghiệp', 'expense', 'debit', '6', 2, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 7 — THU NHẬP KHÁC
            // ════════════════════════════════════════════════════════════
            ['7', 'Thu nhập khác', 'revenue', 'credit', null, 1, false],
            ['711', 'Thu nhập khác', 'revenue', 'credit', '7', 2, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 8 — CHI PHÍ KHÁC
            // ════════════════════════════════════════════════════════════
            ['8', 'Chi phí khác', 'expense', 'debit', null, 1, false],
            ['811', 'Chi phí khác', 'expense', 'debit', '8', 2, true],
            ['821', 'Chi phí thuế thu nhập doanh nghiệp', 'expense', 'debit', '8', 2, false],
            ['8211', 'Chi phí thuế TNDN hiện hành', 'expense', 'debit', '821', 3, true],
            ['8212', 'Chi phí thuế TNDN hoãn lại', 'expense', 'debit', '821', 3, true],

            // ════════════════════════════════════════════════════════════
            // LOẠI 9 — XÁC ĐỊNH KẾT QUẢ KINH DOANH
            // ════════════════════════════════════════════════════════════
            ['9', 'Xác định kết quả kinh doanh', 'equity', 'credit', null, 1, false],
            ['911', 'Xác định kết quả kinh doanh', 'equity', 'credit', '9', 2, true],
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
}
