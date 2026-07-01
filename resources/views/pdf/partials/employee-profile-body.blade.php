@php
    /** @var \App\Models\Employee $model */
    $model = $employee['model'];
    $attachments = $employee['attachments'];
    $co = \App\Models\Setting::getGroup('company');
@endphp

<div class="header">
    <div>
        <div class="company-name">{{ $co['company_name'] ?? 'Mini ERP' }}</div>
        <div class="company-info">{{ $co['company_address'] ?? '' }}</div>
    </div>
    <div class="doc-title">
        <h1>Hồ sơ nhân viên</h1>
        <div class="doc-code">{{ $model->code }}</div>
    </div>
</div>

<div class="meta-grid">
    <div class="meta-box">
        <h3>Thông tin cá nhân</h3>
        <div class="meta-row"><strong>Họ và tên:</strong> <span>{{ $model->name }}</span></div>
        <div class="meta-row"><strong>Giới tính:</strong> <span>{{ $model->gender === 'male' ? 'Nam' : ($model->gender === 'female' ? 'Nữ' : '—') }}</span></div>
        <div class="meta-row"><strong>Ngày sinh:</strong> <span>{{ $model->birth_date?->format('d/m/Y') ?? '—' }}</span></div>
        <div class="meta-row"><strong>CCCD/CMND:</strong> <span>{{ $model->national_id ?? '—' }}</span></div>
        <div class="meta-row"><strong>Ngày cấp:</strong> <span>{{ $model->national_id_issue_date?->format('d/m/Y') ?? '—' }}</span></div>
        <div class="meta-row"><strong>Nơi cấp:</strong> <span>{{ $model->national_id_issue_place ?? '—' }}</span></div>
    </div>
    <div class="meta-box">
        <h3>Thông tin liên hệ</h3>
        <div class="meta-row"><strong>Điện thoại:</strong> <span>{{ $model->phone ?? '—' }}</span></div>
        <div class="meta-row"><strong>Email:</strong> <span>{{ $model->email ?? '—' }}</span></div>
        <div class="meta-row"><strong>Địa chỉ:</strong> <span>{{ $model->address ?? '—' }}</span></div>
    </div>
</div>

<div class="meta-grid">
    <div class="meta-box">
        <h3>Thông tin công việc</h3>
        <div class="meta-row"><strong>Phòng ban:</strong> <span>{{ $model->department ?? '—' }}</span></div>
        <div class="meta-row"><strong>Chức vụ:</strong> <span>{{ $model->position ?? '—' }}</span></div>
        <div class="meta-row"><strong>Ngày vào làm:</strong> <span>{{ $model->hire_date?->format('d/m/Y') ?? '—' }}</span></div>
        <div class="meta-row"><strong>Trạng thái:</strong> <span>{{ $model->status->label() }}</span></div>
    </div>
    <div class="meta-box">
        <h3>Thông tin hợp đồng</h3>
        <div class="meta-row"><strong>Loại hợp đồng:</strong> <span>{{ $model->employment_type->label() }}</span></div>
        <div class="meta-row"><strong>Ngày bắt đầu:</strong> <span>{{ $model->contract_start_date?->format('d/m/Y') ?? '—' }}</span></div>
        <div class="meta-row"><strong>Ngày kết thúc:</strong> <span>{{ $model->contract_end_date?->format('d/m/Y') ?? '—' }}</span></div>
    </div>
</div>

<div class="meta-grid">
    <div class="meta-box">
        <h3>Lương &amp; bảo hiểm</h3>
        <div class="meta-row"><strong>Lương cơ bản:</strong> <span>{{ number_format((float) $model->base_salary, 0, ',', '.') }} đ</span></div>
        <div class="meta-row"><strong>Phụ cấp:</strong> <span>{{ number_format((float) $model->totalAllowances(), 0, ',', '.') }} đ</span></div>
        <div class="meta-row"><strong>Mã số thuế cá nhân:</strong> <span>{{ $model->pit_tax_code ?? '—' }}</span></div>
        <div class="meta-row"><strong>Số BHXH:</strong> <span>{{ $model->social_insurance_no ?? '—' }}</span></div>
    </div>
    <div class="meta-box">
        <h3>Thông tin ngân hàng</h3>
        <div class="meta-row"><strong>Số tài khoản:</strong> <span>{{ $model->bank_account_no ?? '—' }}</span></div>
        <div class="meta-row"><strong>Ngân hàng:</strong> <span>{{ $model->bank_name ?? '—' }}</span></div>
    </div>
</div>

@if ($attachments->isNotEmpty())
<div class="notes-box">
    <h3>Tài liệu đính kèm</h3>
    @foreach ($attachments as $att)
        <div class="meta-row">- {{ $att->file_name }}</div>
    @endforeach
</div>
@endif

<div class="sign-row">
    <div class="sign-box">
        <div class="sign-title">Người lập</div>
        <div class="sign-name">&nbsp;</div>
    </div>
    <div class="sign-box">
        <div class="sign-title">Phòng nhân sự</div>
        <div class="sign-name">&nbsp;</div>
    </div>
    <div class="sign-box">
        <div class="sign-title">Người lao động</div>
        <div class="sign-name">&nbsp;</div>
    </div>
</div>
