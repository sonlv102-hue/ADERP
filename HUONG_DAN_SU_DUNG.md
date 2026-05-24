# Hướng dẫn sử dụng hệ thống Mini ERP

> **Phiên bản:** 1.0 — Cập nhật: 2026-05-21  
> **Dành cho:** Tất cả người dùng hệ thống

---

## Mục lục

1. [Tổng quan hệ thống](#1-tổng-quan-hệ-thống)
2. [Đăng nhập & giao diện](#2-đăng-nhập--giao-diện)
3. [Phân quyền người dùng](#3-phân-quyền-người-dùng)
4. [CRM — Khách hàng](#4-crm--khách-hàng)
5. [Bán hàng — Báo giá](#5-bán-hàng--báo-giá)
6. [Bán hàng — Đơn hàng](#6-bán-hàng--đơn-hàng)
7. [Bán hàng — Hợp đồng](#7-bán-hàng--hợp-đồng)
8. [Bán hàng — Hoa hồng](#8-bán-hàng--hoa-hồng)
9. [Kho hàng](#9-kho-hàng)
10. [Mua hàng](#10-mua-hàng)
11. [Dự án thi công IT](#11-dự-án-thi-công-it)
12. [Hỗ trợ kỹ thuật & Bảo hành](#12-hỗ-trợ-kỹ-thuật--bảo-hành)
13. [Kế toán — Hóa đơn đầu ra](#13-kế-toán--hóa-đơn-đầu-ra)
14. [Chứng từ](#14-chứng-từ)
15. [Báo cáo lợi nhuận](#15-báo-cáo-lợi-nhuận)
16. [Quản trị hệ thống (Admin)](#16-quản-trị-hệ-thống-admin)
17. [Câu hỏi thường gặp](#17-câu-hỏi-thường-gặp)

---

## 1. Tổng quan hệ thống

Mini ERP là hệ thống quản lý nội bộ tích hợp dành cho doanh nghiệp kinh doanh và thi công giải pháp IT, bao gồm các nghiệp vụ:

| Module | Chức năng chính |
|--------|----------------|
| CRM | Quản lý khách hàng, lịch sử giao dịch |
| Bán hàng | Báo giá → Đơn hàng → Hợp đồng → Hoa hồng |
| Kho hàng | Sản phẩm, nhập/xuất kho, tồn kho |
| Mua hàng | Đơn mua, hóa đơn đầu vào, thanh toán NCC |
| Dự án | Quản lý dự án thi công, công việc, nhân sự, chi phí |
| Hỗ trợ KT | Ticket kỹ thuật, bảo hành thiết bị |
| Kế toán | Hóa đơn đầu ra, theo dõi thanh toán |
| Chứng từ | Lưu trữ và tra cứu chứng từ nội bộ |
| Báo cáo | Lợi nhuận đơn hàng, lợi nhuận dự án |

---

## 2. Đăng nhập & giao diện

### 2.1 Đăng nhập

1. Mở trình duyệt, truy cập địa chỉ hệ thống (ví dụ: `http://erp.congty.vn`)
2. Nhập **Email** và **Mật khẩu** được cấp
3. Nhấn **Đăng nhập**

> **Lưu ý:** Nếu quên mật khẩu, liên hệ Admin để được đặt lại.

### 2.2 Giao diện chính

Sau khi đăng nhập, màn hình chia làm 3 vùng:

```
┌──────────────┬────────────────────────────────┐
│              │  THANH TIÊU ĐỀ (Header)         │
│   SIDEBAR    ├────────────────────────────────┤
│  (Menu trái) │                                │
│              │      NỘI DUNG CHÍNH            │
│  - Dashboard │      (thay đổi theo menu)      │
│  - CRM       │                                │
│  - Bán hàng  │                                │
│  - Kho hàng  │                                │
│  - ...       │                                │
└──────────────┴────────────────────────────────┘
```

- **Sidebar trái:** Menu điều hướng, hiển thị theo quyền của từng người dùng
- **Header:** Tên công ty, thông tin tài khoản, nút đăng xuất
- **Vùng nội dung:** Hiển thị trang làm việc hiện tại

### 2.3 Đăng xuất

Nhấn vào **tên người dùng** ở góc trên phải → chọn **Đăng xuất**.

---

## 3. Phân quyền người dùng

Hệ thống có 7 vai trò (role), mỗi vai trò được cấp quyền truy cập khác nhau:

| Vai trò | Mô tả | Có thể làm |
|---------|-------|-----------|
| **Admin** | Quản trị viên | Tất cả chức năng + quản lý users |
| **Director** | Giám đốc | Xem toàn bộ, duyệt hoa hồng cấp 2 |
| **Sales** | Nhân viên kinh doanh | CRM, Báo giá, Đơn hàng, Hợp đồng, Hoa hồng |
| **Kho (Warehouse)** | Thủ kho | Kho hàng, Nhập/Xuất kho, Sản phẩm |
| **KT (Kế toán)** | Kế toán | Hóa đơn đầu ra, thanh toán hoa hồng |
| **Ketoan** | Kế toán cấp cao | Báo cáo lợi nhuận |
| **CSKH** | Chăm sóc khách hàng | Ticket kỹ thuật, Bảo hành |

> Admin có thể xem và thay đổi quyền của từng người dùng trong mục **Quản trị → Người dùng**.

---

## 4. CRM — Khách hàng

### 4.1 Xem danh sách khách hàng

**Menu:** CRM → Khách hàng

Màn hình hiển thị bảng danh sách khách hàng với các cột:
- Mã KH (định dạng KH-XXXX)
- Tên khách hàng
- Người phụ trách
- Số điện thoại, Email
- Trạng thái

**Tìm kiếm:** Nhập tên hoặc mã KH vào ô tìm kiếm → nhấn **Enter** hoặc nút **Lọc**.

### 4.2 Thêm khách hàng mới

1. Nhấn nút **+ Thêm khách hàng** (góc trên phải)
2. Điền thông tin:
   - **Tên khách hàng** *(bắt buộc)*
   - **Loại:** Cá nhân / Doanh nghiệp
   - **Số điện thoại, Email**
   - **Địa chỉ, Mã số thuế** (dành cho doanh nghiệp)
   - **Người phụ trách** (chọn từ danh sách nhân viên)
   - **Ghi chú**
3. Nhấn **Lưu**

Mã khách hàng (KH-XXXX) được tạo tự động.

### 4.3 Xem chi tiết / Sửa khách hàng

- Nhấn vào **Mã KH** trong danh sách để xem chi tiết
- Trang chi tiết hiển thị lịch sử báo giá, đơn hàng, hợp đồng liên quan
- Nhấn nút **Sửa** để chỉnh sửa thông tin

---

## 5. Bán hàng — Báo giá

### 5.1 Quy trình báo giá

```
Nháp → Đã gửi KH → Đã chấp nhận → Chuyển thành ĐH
                 → Từ chối
                 → Hết hạn
```

### 5.2 Tạo báo giá mới

**Menu:** Bán hàng → Báo giá → **+ Tạo báo giá**

1. Chọn **Khách hàng**
2. Điền **Ngày hiệu lực đến**
3. Thêm sản phẩm/dịch vụ:
   - Nhấn **+ Thêm dòng**
   - Chọn sản phẩm hoặc dịch vụ
   - Nhập số lượng và đơn giá bán
4. Chọn **Thuế VAT** (nếu có)
5. Nhập **Ghi chú** (tùy chọn)
6. Nhấn **Lưu** → Báo giá ở trạng thái **Nháp**

Mã báo giá (BG-XXXX) được tạo tự động.

### 5.3 Gửi báo giá cho khách hàng

1. Mở báo giá cần gửi
2. Nhấn nút **Gửi cho khách hàng**
3. Trạng thái chuyển sang **Đã gửi KH**

> **Xuất PDF:** Nhấn nút **Xuất PDF** để tải file báo giá có logo và thông tin công ty.

### 5.4 Chuyển báo giá thành đơn hàng

Khi khách đồng ý:
1. Mở báo giá (trạng thái **Đã gửi KH**)
2. Nhấn **Chấp nhận** → trạng thái chuyển sang **Đã chấp nhận**
3. Nhấn **Tạo đơn hàng** → hệ thống tự động tạo đơn hàng từ báo giá

---

## 6. Bán hàng — Đơn hàng

### 6.1 Quy trình đơn hàng

```
Nháp → Đang xử lý → Hoàn thành
                  → Đã hủy
```

### 6.2 Tạo đơn hàng mới

**Menu:** Bán hàng → Đơn hàng → **+ Tạo đơn hàng**

Tương tự báo giá, có thêm trường:
- **Ngày đặt hàng**
- **Phương thức thanh toán**
- **Hạn giao hàng**

Mã đơn hàng (DH-XXXX) được tạo tự động.

### 6.3 Xử lý đơn hàng

1. Mở đơn hàng → nhấn **Bắt đầu xử lý** (Nháp → Đang xử lý)
2. Khi giao hàng xong → nhấn **Hoàn thành**

> **Lưu ý:** Hoàn thành đơn hàng sẽ kích hoạt tạo hóa đơn trong module Kế toán.

### 6.4 Xuất kho từ đơn hàng

Khi trạng thái là **Đang xử lý**, có thể xuất kho:
1. Mở đơn hàng → tab **Xuất kho**
2. Chọn kho xuất, xác nhận số lượng
3. Nhấn **Tạo phiếu xuất kho**

---

## 7. Bán hàng — Hợp đồng

### 7.1 Tạo hợp đồng

**Menu:** Bán hàng → Hợp đồng → **+ Tạo hợp đồng**

Thông tin cần điền:
- **Khách hàng** *(bắt buộc)*
- **Liên kết đơn hàng** (tùy chọn)
- **Ngày ký, Ngày hiệu lực, Ngày hết hạn**
- **Giá trị hợp đồng**
- **Điều khoản thanh toán**
- **Ghi chú**

Mã hợp đồng (HD-XXXX) được tạo tự động.

### 7.2 Trạng thái hợp đồng

```
Nháp → Hiệu lực → Hoàn thành
                → Hết hạn
                → Đã hủy
```

> **Xuất PDF:** Hợp đồng có thể xuất PDF với mẫu có thông tin công ty và chữ ký.

---

## 8. Bán hàng — Hoa hồng

### 8.1 Quy trình duyệt hoa hồng (2 cấp)

```
Nháp → Chờ TP duyệt → Chờ GĐ duyệt → Chờ thanh toán → Đã thanh toán
                    → Từ chối (ở bất kỳ bước nào)
     → Đã hủy
```

### 8.2 Tạo yêu cầu hoa hồng

**Menu:** Bán hàng → Hoa hồng → **+ Tạo hoa hồng**

Thông tin cần điền:
- **Loại hoa hồng:** Giới thiệu KH / Môi giới dự án / Chăm sóc KH / Tiếp khách / Hỗ trợ bán hàng / Sau bán hàng / Chiết khấu thương mại / Chiết khấu thanh toán / Chi phí đối tác triển khai / Chi phí cộng tác viên
- **Tên người nhận, Thông tin liên hệ**
- **Số tiền** hoặc **Tỷ lệ %**
- **Liên kết:** Khách hàng / Đơn hàng / Dự án (tùy chọn)
- **Phương thức thanh toán, Ngày dự kiến TT**
- **Ghi chú**

### 8.3 Nộp hồ sơ duyệt

1. Mở hoa hồng (trạng thái **Nháp**)
2. Nhấn **Nộp duyệt** → chuyển sang **Chờ TP duyệt**

### 8.4 Trưởng phòng duyệt (cấp 1)

> Yêu cầu quyền `commissions.approve_l1` (thường là Trưởng phòng kinh doanh)

1. Mở hoa hồng đang **Chờ TP duyệt**
2. Kiểm tra thông tin
3. Nhấn **Duyệt cấp 1** → chuyển sang **Chờ GĐ duyệt**  
   hoặc nhấn **Từ chối** → nhập lý do → xác nhận

### 8.5 Giám đốc duyệt (cấp 2)

> Yêu cầu quyền `commissions.approve` (thường là Director hoặc Admin)

1. Mở hoa hồng đang **Chờ GĐ duyệt**
2. Nhấn **Duyệt cấp 2** → chuyển sang **Chờ thanh toán**

### 8.6 Thanh toán hoa hồng

> Yêu cầu quyền `commissions.pay` (thường là Kế toán)

1. Mở hoa hồng đang **Chờ thanh toán**
2. Nhấn **Thanh toán**
3. Chọn **Ngày thanh toán thực tế**
4. Xác nhận → trạng thái chuyển sang **Đã thanh toán**

---

## 9. Kho hàng

### 9.1 Danh mục sản phẩm

**Menu:** Kho hàng → Sản phẩm

Thông tin sản phẩm:
- Mã SP (SP-XXXX), Tên, Danh mục
- Đơn vị tính, Giá vốn, Giá bán
- Tồn kho hiện tại (tổng hợp từ các kho)

**Thêm sản phẩm:** Nhấn **+ Thêm sản phẩm** → điền đầy đủ thông tin → **Lưu**

### 9.2 Dịch vụ

**Menu:** Kho hàng → Dịch vụ

Tương tự sản phẩm nhưng không theo dõi tồn kho. Mã dịch vụ (DV-XXXX).

### 9.3 Nhập kho

**Menu:** Kho hàng → Nhập kho → **+ Tạo phiếu nhập**

1. Chọn **Kho nhập**
2. Chọn **Nhà cung cấp** (tùy chọn)
3. Liên kết **Đơn mua hàng** (nếu có)
4. Thêm sản phẩm và số lượng
5. Nhập **Giá nhập, Ngày nhập**
6. Nhấn **Lưu** → Tồn kho tự động tăng

Mã phiếu nhập (NK-XXXX) được tạo tự động.

### 9.4 Xuất kho

**Menu:** Kho hàng → Xuất kho → **+ Tạo phiếu xuất**

Tương tự nhập kho nhưng chọn kho xuất và liên kết đơn hàng (tùy chọn).

Mã phiếu xuất (XK-XXXX) được tạo tự động.

### 9.5 Quản lý kho

**Menu:** Kho hàng → Kho hàng

- Xem danh sách kho
- Xem tồn kho theo từng kho
- Thêm/sửa thông tin kho

---

## 10. Mua hàng

### 10.1 Đơn mua hàng

**Menu:** Mua hàng → Đơn mua hàng

#### Quy trình đơn mua hàng:
```
Nháp → Đã gửi NCC → Đã nhận hàng
                   → Đã hủy
```

#### Tạo đơn mua:
1. Nhấn **+ Tạo đơn mua** → chọn Nhà cung cấp
2. Thêm sản phẩm cần mua, số lượng, giá mua
3. Nhập ngày đặt, hạn giao dự kiến
4. Nhấn **Lưu**

#### Xác nhận nhận hàng:
1. Khi nhận hàng thực tế → mở đơn mua (trạng thái **Đã gửi NCC**)
2. Nhấn **Xác nhận nhận hàng**
3. Hệ thống **tự động tạo phiếu nhập kho**

### 10.2 Hóa đơn đầu vào (từ Nhà cung cấp)

**Menu:** Mua hàng → Hóa đơn đầu vào

#### Quy trình hóa đơn đầu vào:
```
Chưa nhận HĐ → Đã nhận HĐ → Đang kiểm tra → Hợp lệ → TT một phần → Đã thanh toán
                                            → Cần bổ sung
                                                         → Đã hủy
```

#### Tạo hóa đơn đầu vào:
1. Nhấn **+ Tạo hóa đơn**
2. Chọn **Đơn mua hàng** liên quan (tùy chọn, sẽ tự điền NCC)
3. Nhập thông tin hóa đơn NCC:
   - **Số hóa đơn** (của NCC)
   - **Ngày hóa đơn, Hạn thanh toán**
   - **Mã số thuế NCC**
   - **Tiền hàng chưa thuế, Thuế VAT, Tổng tiền**
4. Nhấn **Lưu**

Mã hóa đơn (HD-NCC-XXXX) được tạo tự động.

#### Chuyển trạng thái:
- Nhận hóa đơn giấy → nhấn **Đã nhận HĐ**
- Kiểm tra hóa đơn → nhấn **Đang kiểm tra**
- Sau khi xác nhận hợp lệ → nhấn **Hợp lệ**
- Nếu thiếu chứng từ → nhấn **Cần bổ sung**

#### Ghi nhận thanh toán:
1. Khi hóa đơn ở trạng thái **Hợp lệ** hoặc **TT một phần**
2. Cuộn xuống phần **Ghi nhận thanh toán**
3. Điền: Số tiền, Ngày TT, Phương thức, Số tham chiếu (nếu có)
4. Nhấn **Ghi nhận**
5. Hệ thống tự tính **Đã TT** và **Còn lại**; tự chuyển sang **TT một phần** hoặc **Đã thanh toán**

---

## 11. Dự án thi công IT

### 11.1 Tạo dự án mới

**Menu:** Dự án thi công → Dự án → **+ Tạo dự án**

Thông tin:
- **Tên dự án, Mô tả**
- **Khách hàng**
- **Liên kết Hợp đồng** (để tính doanh thu trong báo cáo)
- **Ngân sách, Ngày bắt đầu, Ngày dự kiến hoàn thành**

Mã dự án (DA-XXXX) được tạo tự động.

### 11.2 Quản lý dự án — 5 tabs

#### Tab Công việc (Tasks)
- Nhấn **+ Thêm công việc** → điền tên, người phụ trách, hạn hoàn thành
- Tick checkbox để đánh dấu hoàn thành
- Xóa công việc bằng nút xóa

#### Tab Nhân sự
- Nhấn **+ Thêm thành viên** → chọn người dùng và vai trò trong dự án
- Xóa thành viên bằng nút xóa

#### Tab Vật tư
- Nhấn **+ Thêm vật tư** → chọn sản phẩm, nhập số lượng và đơn giá vật tư
- Tổng vật tư được tính tự động

#### Tab Chi phí phát sinh
- Nhấn **+ Thêm chi phí** → điền danh mục, mô tả, số tiền, ngày phát sinh
- Theo dõi các chi phí ngoài vật tư (vận chuyển, công tác, nhân công...)

#### Tab Thông tin
- Xem metadata: ngày tạo, người quản lý, tổng tóm tắt tài chính

### 11.3 Theo dõi tiến độ

Header dự án hiển thị:
- **Tiến độ %** (dựa trên công việc hoàn thành / tổng công việc)
- **Ngân sách** vs **Chi phí thực tế** (cảnh báo đỏ nếu vượt ngân sách)
- **Thời gian:** Ngày bắt đầu → Ngày dự kiến hoàn thành

### 11.4 Chuyển trạng thái dự án

```
Lên kế hoạch → Đang thi công → Hoàn thành
                              → Tạm dừng → Đang thi công
              → Đã hủy
```

Nhấn nút chuyển trạng thái ở header trang chi tiết dự án.

---

## 12. Hỗ trợ kỹ thuật & Bảo hành

### 12.1 Ticket kỹ thuật

**Menu:** Hỗ trợ kỹ thuật → Ticket kỹ thuật

#### Quy trình ticket:
```
Mở → Đang xử lý → Đã giải quyết → Đã đóng
```

#### Tạo ticket mới:
1. Nhấn **+ Tạo ticket**
2. Điền:
   - **Tiêu đề** *(bắt buộc)*
   - **Khách hàng, Ưu tiên** (Thấp / Trung bình / Cao / Khẩn cấp)
   - **Danh mục** (Phần cứng / Phần mềm / Mạng / Khác)
   - **Mô tả chi tiết**
   - **Liên kết:** Đơn hàng / Hợp đồng (tùy chọn)
3. Nhấn **Lưu**

Mã ticket (TK-XXXX) được tạo tự động.

#### Xử lý ticket:
1. Mở ticket → nhấn **Bắt đầu xử lý** (Mở → Đang xử lý)
2. **Phân công** nhân viên kỹ thuật (yêu cầu quyền `tickets.assign`)
3. **Thêm ghi chú** để cập nhật tiến độ xử lý
4. Khi xử lý xong → nhấn **Đã giải quyết**
5. Sau khi khách hàng xác nhận → nhấn **Đóng ticket**

#### Xem lịch sử:
Cột bên trái hiển thị toàn bộ lịch sử: ghi chú, thay đổi trạng thái, phân công — theo thứ tự thời gian.

### 12.2 Bảo hành thiết bị

**Menu:** Hỗ trợ kỹ thuật → Bảo hành

- Quản lý thông tin bảo hành từng thiết bị/serial number
- Theo dõi ngày bắt đầu và ngày hết hạn bảo hành
- Liên kết với đơn hàng và khách hàng

---

## 13. Kế toán — Hóa đơn đầu ra

**Menu:** Kế toán → Hóa đơn

### 13.1 Quy trình hóa đơn đầu ra:
```
Nháp → Đã gửi → Đã thanh toán
              → Quá hạn
```

### 13.2 Tạo hóa đơn

Hóa đơn thường được **tạo tự động** khi hoàn thành đơn hàng. Có thể tạo thủ công:

1. Nhấn **+ Tạo hóa đơn**
2. Chọn Khách hàng, Đơn hàng, Hợp đồng (tùy chọn)
3. Nhập ngày phát hành, hạn thanh toán
4. Chọn sản phẩm/dịch vụ và số lượng
5. Nhấn **Lưu**

Mã hóa đơn (HĐ-XXXX) được tạo tự động.

### 13.3 Gửi hóa đơn

1. Mở hóa đơn (trạng thái **Nháp**)
2. Nhấn **Gửi hóa đơn** → trạng thái chuyển sang **Đã gửi**

> **Xuất PDF:** Nhấn **Xuất PDF** để tải hóa đơn dạng PDF có logo và thông tin ngân hàng công ty.

### 13.4 Ghi nhận thanh toán

1. Mở hóa đơn (trạng thái **Đã gửi**)
2. Cuộn xuống phần **Lịch sử thanh toán** → nhấn **+ Ghi nhận**
3. Điền: Ngày TT, Phương thức, Số tham chiếu, Số tiền
4. Nhấn **Lưu**

Góc phải hiển thị: **Tổng cộng / Đã TT / Còn lại**.

Khi thanh toán đủ → nhấn **Đánh dấu đã TT** hoặc hệ thống tự chuyển.

---

## 14. Chứng từ

**Menu:** Chứng từ → Tất cả chứng từ

### 14.1 Tổng quan

Module chứng từ lưu trữ tập trung 15+ loại chứng từ nội bộ, liên kết đa hướng với các đối tượng khác (đơn hàng, dự án, hợp đồng...).

Mã chứng từ bắt đầu bằng tiền tố **CT-** và loại chứng từ.

### 14.2 Tải lên chứng từ

1. Nhấn **+ Tải lên chứng từ**
2. Chọn **Loại chứng từ**
3. Chọn **File** (PDF, Word, Excel, ảnh...)
4. Điền tên, mô tả, liên kết đối tượng
5. Nhấn **Lưu**

### 14.3 Tải xuống / Xem chứng từ

Nhấn vào tên file trong danh sách → tải xuống hoặc xem trực tuyến.

### 14.4 Quản lý loại chứng từ (Admin)

**Menu:** Chứng từ → Loại chứng từ *(chỉ Admin)*

Thêm, sửa, xóa các loại chứng từ theo nhu cầu doanh nghiệp.

---

## 15. Báo cáo lợi nhuận

**Menu:** Báo cáo → Lợi nhuận đơn hàng / Lợi nhuận dự án

> Yêu cầu quyền `reports.view` (thường là Director, Admin, Kế toán cấp cao)

### 15.1 Báo cáo lợi nhuận đơn hàng

Hiển thị lợi nhuận thực tế của từng đơn hàng:

| Cột | Giải thích |
|-----|-----------|
| Doanh thu | Tổng hóa đơn đầu ra (chưa VAT) liên kết với đơn |
| Giá vốn | Số lượng × Giá vốn sản phẩm |
| Hoa hồng | Tổng hoa hồng đã duyệt liên kết đơn hàng |
| Lợi nhuận | Doanh thu − Giá vốn − Hoa hồng |
| Tỷ suất % | Lợi nhuận / Doanh thu × 100 |

**Thẻ tóm tắt:** Doanh thu / Giá vốn / Hoa hồng / Lợi nhuận tổng của trang hiện tại.

**Lọc:** Theo từ khóa (mã đơn, tên KH) và khoảng ngày đặt hàng.

### 15.2 Báo cáo lợi nhuận dự án

Hiển thị lợi nhuận thực tế của từng dự án:

| Cột | Giải thích |
|-----|-----------|
| Doanh thu | Hóa đơn liên kết qua Hợp đồng của dự án |
| Vật tư | Tổng chi phí vật tư trong dự án |
| Chi phí PS | Tổng chi phí phát sinh |
| Hoa hồng | Hoa hồng đã duyệt liên kết dự án |
| Tổng CP | Vật tư + Chi phí PS + Hoa hồng |
| Lợi nhuận | Doanh thu − Tổng CP |
| Tỷ suất % | Lợi nhuận / Doanh thu × 100 |

**Lọc:** Theo từ khóa (mã DA, tên, KH) và khoảng ngày bắt đầu.

> **Lưu ý:** Dự án không liên kết Hợp đồng sẽ có doanh thu = 0. Cần liên kết hợp đồng để báo cáo chính xác.

---

## 16. Quản trị hệ thống (Admin)

> Chỉ tài khoản vai trò **Admin** mới thấy mục này.

### 16.1 Quản lý người dùng

**Menu:** Quản trị → Người dùng

- Xem danh sách người dùng, trạng thái kích hoạt
- **Thêm người dùng mới:**
  1. Nhấn **+ Thêm người dùng**
  2. Điền họ tên, email, mật khẩu
  3. Chọn **Vai trò** (role)
  4. Nhấn **Lưu**
- **Sửa người dùng:** Nhấn vào tên → chọn **Sửa**
- **Đặt lại mật khẩu:** Sửa → thay đổi trường mật khẩu → Lưu

### 16.2 Cài đặt công ty

**Menu:** Quản trị → Cài đặt công ty

Các thông tin có thể cấu hình:

| Thông tin | Mô tả |
|-----------|-------|
| Tên công ty | Hiển thị trên sidebar và PDF |
| Logo công ty | Tải lên ảnh PNG/JPG (khuyến nghị 200×200px) |
| Địa chỉ | Địa chỉ công ty trên PDF |
| Số điện thoại, Email | Liên hệ trên PDF |
| Mã số thuế | In trên hóa đơn, báo giá |
| Thông tin ngân hàng | Số TK, Ngân hàng, Chi nhánh — in trên hóa đơn |

Sau khi thay đổi → nhấn **Lưu cài đặt**.

---

## 17. Câu hỏi thường gặp

### Q: Tôi không thấy menu [X] dù đã đăng nhập?
**A:** Menu chỉ hiển thị theo quyền của tài khoản. Liên hệ Admin để được cấp thêm quyền nếu cần.

---

### Q: Mã đơn hàng/báo giá/... được tạo như thế nào?
**A:** Tất cả mã được hệ thống tạo tự động theo dãy số tăng dần. Không thể thay đổi thủ công.

| Loại | Định dạng |
|------|----------|
| Khách hàng | KH-0001, KH-0002... |
| Báo giá | BG-0001, BG-0002... |
| Đơn hàng | DH-0001, DH-0002... |
| Hợp đồng | HD-0001, HD-0002... |
| Hoa hồng | HOA-0001, HOA-0002... |
| Đơn mua hàng | MH-0001, MH-0002... |
| Hóa đơn NCC | HD-NCC-0001... |
| Dự án | DA-0001, DA-0002... |
| Ticket | TK-0001, TK-0002... |
| Nhập kho | NK-0001, NK-0002... |
| Xuất kho | XK-0001, XK-0002... |

---

### Q: Tôi đã chuyển trạng thái nhầm, có hủy được không?
**A:** Một số trạng thái cho phép hủy (Đã hủy). Tuy nhiên hầu hết các bước không thể đảo ngược. Hãy kiểm tra kỹ trước khi nhấn xác nhận.

---

### Q: Tôi tạo đơn mua hàng, hàng đã nhận nhưng kho chưa tăng?
**A:** Cần nhấn **Xác nhận nhận hàng** trong đơn mua (chuyển sang trạng thái **Đã nhận hàng**). Khi đó hệ thống mới tự động tạo phiếu nhập kho.

---

### Q: Báo cáo lợi nhuận dự án hiển thị doanh thu = 0?
**A:** Dự án cần được **liên kết với Hợp đồng**. Hóa đơn được tính doanh thu thông qua Hợp đồng. Vào trang chi tiết dự án → **Sửa** → chọn Hợp đồng phù hợp.

---

### Q: Xuất PDF báo giá/hóa đơn nhưng không thấy logo công ty?
**A:** Admin cần cài đặt logo tại **Quản trị → Cài đặt công ty → Logo công ty**.

---

*Nếu gặp sự cố kỹ thuật, vui lòng liên hệ bộ phận IT hoặc Admin hệ thống.*
