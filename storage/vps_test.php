<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$entries = DB::table('journal_entries')
    ->where('reference_type', 'payroll')
    ->get(['id', 'code', 'status', 'description', 'reference_id', 'notes'])
    ->toArray();

print_r($entries);
