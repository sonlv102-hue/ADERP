<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DatabaseBackup extends Command
{
    protected $signature = 'db:backup {--keep=14 : Số ngày giữ lại bản backup}';
    protected $description = 'Tạo bản sao lưu cơ sở dữ liệu PostgreSQL';

    public function handle(): int
    {
        $host     = config('database.connections.pgsql.host');
        $port     = config('database.connections.pgsql.port', 5432);
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        if (! $host || ! $database) {
            $this->error('Không tìm thấy cấu hình database PostgreSQL.');
            return self::FAILURE;
        }

        $filename  = 'backup_' . now()->format('Y-m-d_His') . '.sql.gz';
        $localPath = storage_path('app/backups/' . $filename);

        if (! is_dir(storage_path('app/backups'))) {
            mkdir(storage_path('app/backups'), 0755, true);
        }

        $cmd = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s %s | gzip > %s 2>&1',
            escapeshellarg($password),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($localPath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || ! file_exists($localPath) || filesize($localPath) === 0) {
            $this->error('pg_dump thất bại: ' . implode("\n", $output));
            @unlink($localPath);
            return self::FAILURE;
        }

        $this->info("Backup thành công: {$filename} (" . $this->formatBytes(filesize($localPath)) . ')');

        // Xóa bản backup cũ hơn --keep ngày
        $keep = (int) $this->option('keep');
        $files = glob(storage_path('app/backups/backup_*.sql.gz')) ?: [];
        foreach ($files as $file) {
            if (filemtime($file) < now()->subDays($keep)->timestamp) {
                unlink($file);
                $this->line('Đã xóa backup cũ: ' . basename($file));
            }
        }

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
