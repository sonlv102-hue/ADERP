<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceSheetStatus;
use App\Exports\AttendanceSheetExport;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class AttendanceController extends Controller
{
    public function index(): Response
    {
        $sheets = AttendanceSheet::with('creator')
            ->orderByDesc('period')
            ->paginate(20)
            ->through(fn (AttendanceSheet $s) => [
                'id'           => $s->id,
                'code'         => $s->code,
                'period'       => $s->period,
                'status'       => $s->status->value,
                'status_label' => $s->status->label(),
                'status_color' => $s->status->color(),
                'employee_count' => $s->records()->count(),
                'creator'      => $s->creator?->name,
                'created_at'   => $s->created_at->format('d/m/Y'),
            ]);

        return Inertia::render('Admin/Attendance/Index', [
            'sheets' => $sheets,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'notes'  => 'nullable|string|max:500',
        ]);

        if (AttendanceSheet::where('period', $data['period'])->exists()) {
            return back()->with('error', "Bảng chấm công tháng {$data['period']} đã tồn tại.");
        }

        DB::transaction(function () use ($data) {
            $sheet = AttendanceSheet::create([
                'code'       => AttendanceSheet::generateCode($data['period']),
                'period'     => $data['period'],
                'status'     => AttendanceSheetStatus::Draft,
                'notes'      => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Auto-populate from active employees
            $employees = Employee::whereIn('status', ['active', 'probation'])
                ->orderBy('name')
                ->get();

            foreach ($employees as $employee) {
                AttendanceRecord::create([
                    'attendance_sheet_id' => $sheet->id,
                    'employee_id'         => $employee->id,
                    'days'                => [],
                ]);
            }
        });

        return redirect()->route('admin.attendance.index')
            ->with('success', "Bảng chấm công tháng {$data['period']} đã được tạo.");
    }

    public function show(AttendanceSheet $attendance): Response
    {
        $attendance->load('creator');

        [$daysInMonth, $dayHeaders, $records] = $this->buildSheetData($attendance);

        return Inertia::render('Admin/Attendance/Show', [
            'sheet' => [
                'id'           => $attendance->id,
                'code'         => $attendance->code,
                'period'       => $attendance->period,
                'status'       => $attendance->status->value,
                'status_label' => $attendance->status->label(),
                'status_color' => $attendance->status->color(),
                'notes'        => $attendance->notes,
                'creator'      => $attendance->creator?->name,
                'created_at'   => $attendance->created_at->format('d/m/Y H:i'),
            ],
            'records'     => $records,
            'daysInMonth' => $daysInMonth,
            'dayHeaders'  => $dayHeaders, // day => dow (7=Sun)
        ]);
    }

    public function exportExcel(AttendanceSheet $attendance)
    {
        [$daysInMonth, , $records] = $this->buildSheetData($attendance);

        return Excel::download(
            new AttendanceSheetExport($attendance, $records, $daysInMonth),
            'bang-cham-cong_' . $attendance->period . '.xlsx'
        );
    }

    /** @return array{0:int,1:array,2:\Illuminate\Support\Collection} */
    private function buildSheetData(AttendanceSheet $attendance): array
    {
        [$year, $month] = explode('-', $attendance->period);
        $daysInMonth = (int) \Carbon\Carbon::createFromDate((int)$year, (int)$month, 1)->daysInMonth;

        $dayHeaders = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dow = date('N', mktime(0, 0, 0, (int)$month, $d, (int)$year)); // 1=Mon..7=Sun
            $dayHeaders[$d] = $dow; // 7 = Sunday
        }

        $records = $attendance->records()
            ->with('employee')
            ->get()
            ->map(fn (AttendanceRecord $r) => $this->recordDTO($r, $daysInMonth));

        return [$daysInMonth, $dayHeaders, $records];
    }

    /** Save a single cell (one day symbol for one employee) */
    public function updateCell(Request $request, AttendanceSheet $attendance, AttendanceRecord $record): RedirectResponse
    {
        if ($attendance->status === AttendanceSheetStatus::Locked) {
            return back()->with('error', 'Bảng chấm công đã khóa, không thể chỉnh sửa.');
        }

        if ($record->attendance_sheet_id !== $attendance->id) {
            return back()->with('error', 'Bản ghi không thuộc bảng chấm công này.');
        }

        $data = $request->validate([
            'day'    => 'required|integer|min:1|max:31',
            'symbol' => 'nullable|string|max:5',
        ]);

        $days = $record->days ?? [];
        $symbol = strtoupper(trim($data['symbol'] ?? ''));
        if ($symbol === '') {
            unset($days[(string)$data['day']]);
        } else {
            $days[(string)$data['day']] = $symbol;
        }

        $record->days = $days;
        $record->save();
        $record->recalculate();

        return back()->with('success', 'Đã cập nhật.');
    }

    /** Save entire row at once (bulk update from row edit) */
    public function updateRecord(Request $request, AttendanceSheet $attendance, AttendanceRecord $record): RedirectResponse
    {
        if ($attendance->status === AttendanceSheetStatus::Locked) {
            return back()->with('error', 'Bảng chấm công đã khóa, không thể chỉnh sửa.');
        }

        if ($record->attendance_sheet_id !== $attendance->id) {
            return back()->with('error', 'Bản ghi không thuộc bảng chấm công này.');
        }

        $data = $request->validate([
            'days'  => 'nullable|array',
            'days.*' => 'nullable|string|max:5',
            'notes' => 'nullable|string|max:500',
        ]);

        // Normalise: uppercase, remove empty
        $days = [];
        foreach ($data['days'] ?? [] as $day => $symbol) {
            $s = strtoupper(trim($symbol ?? ''));
            if ($s !== '') {
                $days[(string)$day] = $s;
            }
        }

        $record->update(['days' => $days, 'notes' => $data['notes'] ?? $record->notes]);
        $record->recalculate();

        return back()->with('success', 'Đã lưu bảng công nhân viên.');
    }

    public function lock(AttendanceSheet $attendance): RedirectResponse
    {
        if ($attendance->status !== AttendanceSheetStatus::Draft) {
            return back()->with('error', 'Bảng chấm công đã được khóa.');
        }

        $attendance->update(['status' => AttendanceSheetStatus::Locked]);

        return back()->with('success', "Bảng chấm công tháng {$attendance->period} đã được khóa.");
    }

    public function unlock(AttendanceSheet $attendance): RedirectResponse
    {
        if ($attendance->status !== AttendanceSheetStatus::Locked) {
            return back()->with('error', 'Bảng chấm công chưa khóa.');
        }

        $attendance->update(['status' => AttendanceSheetStatus::Draft]);

        return back()->with('success', "Đã mở lại bảng chấm công tháng {$attendance->period}.");
    }

    public function destroy(AttendanceSheet $attendance): RedirectResponse
    {
        if ($attendance->status === AttendanceSheetStatus::Locked) {
            return back()->with('error', 'Không thể xóa bảng chấm công đã khóa.');
        }

        $attendance->delete();

        return redirect()->route('admin.attendance.index')
            ->with('success', 'Đã xóa bảng chấm công.');
    }

    private function recordDTO(AttendanceRecord $r, int $daysInMonth): array
    {
        $days = $r->days ?? [];

        // Build flat array [1..daysInMonth] with symbol or null
        $dayValues = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dayValues[$d] = $days[(string)$d] ?? null;
        }

        return [
            'id'                => $r->id,
            'employee_id'       => $r->employee_id,
            'employee_name'     => $r->employee?->name,
            'employee_code'     => $r->employee?->code,
            'position'          => $r->employee?->position,
            'department'        => $r->employee?->department,
            'days'              => $dayValues,
            'cong'              => $r->cong,
            'nghi_huong_luong'  => $r->nghi_huong_luong,
            'nghi_khong_luong'  => $r->nghi_khong_luong,
            'ot'                => $r->ot,
            'tong'              => $r->tong,
            'notes'             => $r->notes,
        ];
    }
}
