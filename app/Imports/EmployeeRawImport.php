<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class EmployeeRawImport implements ToCollection
{
    /** @var Collection Row 0 = header, from row 1 = data (theo đúng thứ tự cột của Mau_upload_nhan_vien.xlsx) */
    public Collection $rows;

    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }
}
