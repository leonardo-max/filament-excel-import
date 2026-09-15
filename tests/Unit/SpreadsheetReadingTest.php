<?php

namespace HayderHatem\FilamentExcelImport\Tests\Unit;

use HayderHatem\FilamentExcelImport\Tests\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pins the PhpSpreadsheet API this package reads through.
 *
 * The import path is a fixed sequence — `IOFactory::createReaderForFile()`,
 * `setReadDataOnly(true)`, an `IReadFilter`, then a row/cell iterator with
 * `setIterateOnlyExistingCells(false)` and `Cell::getValue()`. Every piece of it
 * was deprecated or re-typed at some point between PhpSpreadsheet 1 and 5, and
 * a break there is silent: the import does not crash, it imports wrong values.
 *
 * Leading zeros are the canonical casualty. PhpSpreadsheet 3.0 changed the Xlsx
 * reader's default cell type from string to numeric when the XML omits `t`, so a
 * code like `00123` can come back as `123` and every CPF, enrolment number and
 * barcode in the sheet quietly loses its zeros.
 */
class SpreadsheetReadingTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && file_exists($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_reads_values_back_in_the_types_they_were_written_with()
    {
        $rows = $this->readBack([
            ['code', 'amount', 'name'],
            ['00123', 42, 'Maria'],
        ]);

        $this->assertSame(['code', 'amount', 'name'], $rows[0]);

        // The whole point: a string stays a string, zeros and all. assertSame
        // carries the type too — `123`, `'123'` and `123.0` all fail it.
        $this->assertSame('00123', $rows[1][0], 'Leading zeros were lost — a code column would be corrupted on import.');

        $this->assertSame(42, $rows[1][1], 'A number came back as something other than a number.');
        $this->assertSame('Maria', $rows[1][2]);
    }

    #[Test]
    public function it_still_walks_over_cells_that_were_never_written()
    {
        // A gap in the middle is why the package calls
        // setIterateOnlyExistingCells(false): without it the iterator skips the
        // empty cell and every column after it shifts one position left.
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->setCellValue('A1', 'first');
        $sheet->getActiveSheet()->setCellValue('C1', 'third');

        $rows = $this->readBackSpreadsheet($sheet);

        $this->assertCount(3, $rows[0], 'The empty cell was skipped and the columns shifted.');
        $this->assertSame('first', $rows[0][0]);
        $this->assertNull($rows[0][1]);
        $this->assertSame('third', $rows[0][2]);
    }

    #[Test]
    public function it_accepts_the_read_filter_shape_the_package_declares()
    {
        $filter = new class () implements IReadFilter {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row === 1;
            }
        };

        $rows = $this->readBack([["kept"], ["dropped"]], $filter);

        $this->assertSame('kept', $rows[0][0]);
        $this->assertNull($rows[1][0] ?? null, 'The read filter did not drop the second row.');
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function readBack(array $rows, ?IReadFilter $filter = null): array
    {
        $spreadsheet = new Spreadsheet();

        foreach ($rows as $y => $row) {
            foreach ($row as $x => $value) {
                $cell = $spreadsheet->getActiveSheet()->getCell([$x + 1, $y + 1]);

                // Written as an explicit string, the way a text-formatted column
                // reaches the file — this is the case PhpSpreadsheet 3 changed.
                if (is_string($value)) {
                    $cell->setValueExplicit($value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

                    continue;
                }

                $cell->setValue($value);
            }
        }

        return $this->readBackSpreadsheet($spreadsheet, $filter);
    }

    /** @return array<int, array<int, mixed>> */
    private function readBackSpreadsheet(Spreadsheet $spreadsheet, ?IReadFilter $filter = null): array
    {
        $this->file = tempnam(sys_get_temp_dir(), 'excel-import-') . '.xlsx';

        (new XlsxWriter($spreadsheet))->save($this->file);
        $spreadsheet->disconnectWorksheets();

        // From here on, the exact sequence src/Actions/Imports/Jobs/ImportExcel.php uses.
        $reader = IOFactory::createReaderForFile($this->file);
        $reader->setReadDataOnly(true);

        if ($filter !== null) {
            $reader->setReadFilter($filter);
        }

        $loaded = $reader->load($this->file);
        $worksheet = $loaded->getSheet(0);
        $highestColumn = $worksheet->getHighestDataColumn();

        $output = [];

        foreach ($worksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator('A', $highestColumn);
            $cellIterator->setIterateOnlyExistingCells(false);

            $line = [];

            foreach ($cellIterator as $cell) {
                $line[] = $cell->getValue();
            }

            $output[] = $line;
        }

        $loaded->disconnectWorksheets();

        return $output;
    }
}
