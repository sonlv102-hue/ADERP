<?php

/*
 * Báo cáo "Kiểm soát sai lệch tồn kho và kế toán".
 * policy_topics: chủ đề CHÍNH SÁCH chờ quyết định — không phải sai lệch dữ liệu, không tính vào số sản phẩm sai lệch.
 * Không chứa SKU/chứng từ/số tiền cụ thể. Phương án chi tiết theo dữ liệu thực tế nhập qua
 * `php artisan inventory:integrity-cases <file.json>` (file nằm ngoài Git).
 */
return [
    // A4: chặn xác nhận phiếu trả hàng khi chưa có quy tắc bút toán được duyệt. Chỉ bật true khi kiểm thử kỹ thuật.
    'returns' => [
        'allow_confirm_without_je' => (bool) env('INVENTORY_RETURN_CONFIRM_WITHOUT_JE', false),
    ],

    'policy_topics' => [
        'A3' => [
            'title' => 'Thu hồi phiếu nhập khi đã có giao dịch phụ thuộc',
            'description' => 'Hiện tại: không có phụ thuộc → đảo đúng SL/giá trị nhập gốc; có phiếu xuất phụ thuộc → chặn và báo danh sách phiếu xuất. Chưa có nghiệp vụ điều chỉnh giá.',
            'proposal' => 'Owner/kế toán trưởng chọn: (1) giữ chặn như hiện tại; (3) phát triển phiếu điều chỉnh giá. Phương án (2) tự tính lại giá vốn quá khứ không khuyến nghị.',
            'options' => [
                ['name' => '(1) Giữ chặn', 'impact' => 'Không phát sinh JE; tồn kho luôn đúng; thao tác thủ công nhiều hơn'],
                ['name' => '(2) Cho thu hồi, tính lại giá vốn', 'impact' => 'Sửa JE nhiều phiếu, có thể thuộc kỳ đã khóa — rủi ro cao'],
                ['name' => '(3) Phiếu điều chỉnh giá', 'impact' => '1 JE điều chỉnh ở kỳ hiện tại; cần phát triển thêm'],
            ],
        ],
        'A4' => [
            'title' => 'Hạch toán trả hàng bán, trả hàng NCC và giá trị kiểm kê',
            'description' => 'Hiện tại: phiếu trả hàng bán/NCC được lập và lưu nháp nhưng BỊ CHẶN xác nhận vì chưa có quy tắc bút toán (giá trị tồn khi xác nhận: trả bán theo giá vốn phiếu xuất gốc, trả NCC theo giá nhập gốc). Phiếu lịch sử đã xác nhận thiếu JE hiển thị là RETURN_WITHOUT_JE. Kiểm kê thiếu/thừa có JE theo AVCO (TK đối ứng theo cấu hình 811/711); thừa khi chưa có giá bình quân bị chặn.',
            'proposal' => 'Kế toán trưởng xác nhận bút toán: trả hàng bán (Nợ 156/Có 632?), trả hàng NCC (Nợ 331/Có 156, Có 1331?), kiểm kê thiếu (1381/632/811?), kiểm kê thừa (giá AVCO hay nhập tay; 3381/711?).',
            'options' => [
                ['name' => 'Trả hàng bán', 'impact' => 'Đề xuất Nợ 156 / Có 632 theo giá vốn gốc; doanh thu/thuế qua hóa đơn điều chỉnh'],
                ['name' => 'Trả hàng NCC', 'impact' => 'Đề xuất Nợ 331 / Có 156 (giá gốc) và Có 1331 (VAT tương ứng)'],
                ['name' => 'Kiểm kê', 'impact' => 'Xác nhận TK đối ứng và phương pháp giá cho hàng thừa'],
            ],
        ],
    ],
];
