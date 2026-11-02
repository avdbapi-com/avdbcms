<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class UsersExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public string $fileName = 'users';

    private array $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function collection() : Collection
    {
        return User::filter($this->filters)->get();
    }

    public function headings(): array
    {
        return [
            '#',
            'Username',
            'Email',
            'Admin',
            'Created',
            'Updated',
        ];
    }

    public function map(mixed $row): array
    {
        return [
            $row->id,
            self::cell($row->username),
            self::cell($row->email),
            $row->is_admin,
            Date::dateTimeToExcel($row->created_at),
            Date::dateTimeToExcel($row->updated_at),
        ];
    }

    /**
     * Member text for a spreadsheet cell: a value that starts with = + - @ or a tab / carriage return is a formula to
     * Excel, LibreOffice and Google Sheets (=HYPERLINK(...) can send what is in other cells to another site). A leading
     * apostrophe keeps it plain text there and shows nothing extra.
     */
    private static function cell(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_DATE_DATETIME,
            'F' => NumberFormat::FORMAT_DATE_DATETIME,
        ];
    }
}
