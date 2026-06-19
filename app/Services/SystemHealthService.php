<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SystemHealthService
{
    public function getChecks(): array
    {
        return [
            'environment' => $this->checkEnvironment(),
            'database'    => $this->checkDatabase(),
            'migrations'  => $this->checkMigrations(),
            'storage'     => $this->checkStoragePermissions(),
            'queue'       => $this->checkQueue(),
            'frontend'    => $this->checkFrontend(),
            'log'         => $this->checkLog(),
            'deploy'      => $this->checkDeployMeta(),
            'git'         => $this->checkGit(),
            'maintenance' => $this->checkMaintenance(),
        ];
    }

    private function checkEnvironment(): array
    {
        try {
            return [
                'status' => 'info',
                'label'  => 'Môi trường: ' . config('app.env'),
                'detail' => [
                    'environment'    => config('app.env'),
                    'app_name'       => config('app.name'),
                    'php_version'    => PHP_VERSION,
                    'laravel_version'=> app()->version(),
                ],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'label' => 'Lỗi đọc cấu hình môi trường', 'detail' => $e->getMessage()];
        }
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');
            $driver = config('database.default');
            $db     = config("database.connections.{$driver}.database");

            return [
                'status' => 'ok',
                'label'  => 'Kết nối database: OK',
                'detail' => ['driver' => $driver, 'database' => $db],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'label' => 'Không thể kết nối database', 'detail' => $e->getMessage()];
        }
    }

    private function checkMigrations(): array
    {
        try {
            $ran   = DB::table('migrations')->pluck('migration');
            $files = collect(File::files(database_path('migrations')))
                ->map(fn ($f) => pathinfo($f->getPathname(), PATHINFO_FILENAME));

            $pending = $files->diff($ran)->values();

            if ($pending->isEmpty()) {
                return [
                    'status' => 'ok',
                    'label'  => "Migration: OK ({$ran->count()} đã chạy)",
                    'detail' => ['total' => $files->count(), 'ran' => $ran->count(), 'pending' => []],
                ];
            }

            return [
                'status' => 'warning',
                'label'  => "Migration: Có {$pending->count()} chưa chạy",
                'detail' => ['total' => $files->count(), 'ran' => $ran->count(), 'pending' => $pending->toArray()],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'label' => 'Lỗi kiểm tra migration', 'detail' => $e->getMessage()];
        }
    }

    private function checkStoragePermissions(): array
    {
        $paths = [
            'storage'           => storage_path(),
            'storage/logs'      => storage_path('logs'),
            'storage/framework' => storage_path('framework'),
            'bootstrap/cache'   => base_path('bootstrap/cache'),
        ];

        $results  = [];
        $hasError = false;

        foreach ($paths as $label => $path) {
            $exists   = File::isDirectory($path);
            $writable = $exists && is_writable($path);
            if (! $writable) {
                $hasError = true;
            }
            $results[] = ['path' => $label, 'exists' => $exists, 'writable' => $writable];
        }

        return [
            'status' => $hasError ? 'error' : 'ok',
            'label'  => $hasError ? 'Quyền thư mục: Có vấn đề' : 'Quyền thư mục: OK',
            'detail' => $results,
        ];
    }

    private function checkQueue(): array
    {
        try {
            $connection  = config('queue.default');
            $failedCount = 0;
            $lastFailed  = null;

            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $failedCount = DB::table('failed_jobs')->count();
                $last        = DB::table('failed_jobs')->orderByDesc('failed_at')->value('failed_at');
                $lastFailed  = $last;
            }

            return [
                'status' => $failedCount > 0 ? 'warning' : 'ok',
                'label'  => $failedCount > 0 ? "Queue: {$failedCount} job thất bại" : 'Queue: OK',
                'detail' => ['connection' => $connection, 'failed_jobs' => $failedCount, 'last_failed_at' => $lastFailed],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'warning', 'label' => 'Queue: Không kiểm tra được', 'detail' => $e->getMessage()];
        }
    }

    private function checkFrontend(): array
    {
        try {
            $manifestPath = public_path('build/manifest.json');
            if (! file_exists($manifestPath)) {
                return [
                    'status' => 'warning',
                    'label'  => 'Frontend build: Chưa có file manifest',
                    'detail' => 'Chưa tìm thấy public/build/manifest.json. Vui lòng chạy npm run build khi deploy.',
                ];
            }

            $builtAt = date('Y-m-d H:i:s', filemtime($manifestPath));

            return [
                'status' => 'ok',
                'label'  => 'Frontend build: OK',
                'detail' => ['manifest' => 'public/build/manifest.json', 'built_at' => $builtAt],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'label' => 'Lỗi kiểm tra frontend build', 'detail' => $e->getMessage()];
        }
    }

    private function checkLog(): array
    {
        try {
            $logPath = storage_path('logs/laravel.log');
            if (! file_exists($logPath)) {
                return ['status' => 'ok', 'label' => 'Laravel Log: Chưa có file log', 'detail' => [], 'log_size_kb' => 0];
            }

            $size = filesize($logPath);
            $fp   = fopen($logPath, 'rb');
            fseek($fp, max(0, $size - 51200));
            $content = fread($fp, 51200);
            fclose($fp);

            $errors = [];
            foreach (explode("\n", $content) as $line) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*?\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.+)$/i', $line, $m)) {
                    $errors[] = [
                        'time'    => $m[1],
                        'level'   => strtoupper($m[2]),
                        'message' => Str::limit(trim($m[3]), 200),
                    ];
                }
            }

            $errors = array_slice($errors, -10);
            $hasCritical = collect($errors)->contains(fn ($e) => in_array($e['level'], ['CRITICAL', 'ALERT', 'EMERGENCY']));

            return [
                'status'      => empty($errors) ? 'ok' : ($hasCritical ? 'error' : 'warning'),
                'label'       => empty($errors) ? 'Laravel Log: Không có lỗi gần đây' : 'Laravel Log: Có ' . count($errors) . ' lỗi gần đây',
                'detail'      => $errors,
                'log_size_kb' => round($size / 1024, 1),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'warning', 'label' => 'Log: Không đọc được', 'detail' => $e->getMessage(), 'log_size_kb' => 0];
        }
    }

    private function checkDeployMeta(): array
    {
        try {
            $path = storage_path('app/deploy.json');
            if (! file_exists($path)) {
                return [
                    'status' => 'info',
                    'label'  => 'Deploy metadata: Chưa có thông tin',
                    'detail' => 'Chưa có file storage/app/deploy.json.',
                ];
            }

            $meta = json_decode(file_get_contents($path), true) ?? [];
            $safe = array_intersect_key($meta, array_flip([
                'deployed_at', 'branch', 'commit', 'commit_message', 'deployed_by', 'environment',
            ]));

            return [
                'status' => 'ok',
                'label'  => 'Deploy: ' . ($safe['commit'] ?? '?') . ' lúc ' . ($safe['deployed_at'] ?? '?'),
                'detail' => $safe,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'warning', 'label' => 'Deploy metadata: Lỗi đọc file', 'detail' => $e->getMessage()];
        }
    }

    private function checkGit(): array
    {
        try {
            $branch  = trim((string) @shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null'));
            $commit  = trim((string) @shell_exec('git rev-parse --short HEAD 2>/dev/null'));
            $message = trim((string) @shell_exec('git log -1 --pretty=%s 2>/dev/null'));

            if (empty($branch) || empty($commit)) {
                return ['status' => 'info', 'label' => 'Git: Không đọc được thông tin', 'detail' => []];
            }

            return [
                'status' => 'ok',
                'label'  => "Git: {$branch} @ {$commit}",
                'detail' => ['branch' => $branch, 'commit' => $commit, 'message' => $message],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'info', 'label' => 'Git: Không đọc được', 'detail' => $e->getMessage()];
        }
    }

    private function checkMaintenance(): array
    {
        try {
            $down = app()->isDownForMaintenance();

            return [
                'status' => $down ? 'warning' : 'ok',
                'label'  => $down ? 'Maintenance mode: App đang ở chế độ down' : 'Maintenance mode: Bình thường',
                'detail' => ['is_down' => $down],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'info', 'label' => 'Maintenance: Không kiểm tra được', 'detail' => $e->getMessage()];
        }
    }
}
