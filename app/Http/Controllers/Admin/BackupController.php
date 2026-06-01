<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    private string $backupDir;

    public function __construct()
    {
        $this->backupDir = storage_path('app/backups');
    }

    public function index()
    {
        $files = glob($this->backupDir . '/backup_*.sql.gz') ?: [];
        rsort($files);

        $backups = array_map(function ($path) {
            return [
                'name'       => basename($path),
                'size'       => $this->formatBytes(filesize($path)),
                'size_bytes' => filesize($path),
                'created_at' => date('d/m/Y H:i:s', filemtime($path)),
            ];
        }, $files);

        return Inertia::render('Admin/Backups/Index', [
            'backups' => array_values($backups),
        ]);
    }

    public function store()
    {
        Artisan::call('db:backup');
        $output = Artisan::output();

        if (str_contains($output, 'thất bại')) {
            return back()->withErrors(['error' => 'Backup thất bại. ' . $output]);
        }

        return back()->with('success', 'Đã tạo backup thành công.');
    }

    public function download(string $name): BinaryFileResponse
    {
        $path = $this->backupDir . '/' . $name;

        abort_if(
            ! preg_match('/^backup_[\d_]+\.sql\.gz$/', $name) || ! file_exists($path),
            404
        );

        return response()->download($path);
    }

    public function destroy(string $name)
    {
        $path = $this->backupDir . '/' . $name;

        abort_if(
            ! preg_match('/^backup_[\d_]+\.sql\.gz$/', $name) || ! file_exists($path),
            404
        );

        unlink($path);

        return back()->with('success', 'Đã xóa backup.');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
