<?php
/**
 * Shared PhpSpreadsheet styling helper.
 *
 * Call applyXlsxStyles($sheet, $headers, $dataRowCount) after all data has
 * been written to the sheet. Data must start at row 2 (row 1 is the title
 * bar inserted by this function, which shifts everything down by one).
 *
 * Usage pattern in export files:
 *   1. Write headers to row 1, data from row 2 onward.
 *   2. Call applyXlsxStyles($sheet, $headers, count($rows), 'Report Title').
 *      The function inserts a title row ABOVE row 1, shifting everything down.
 */

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

function applyXlsxStyles(Worksheet $sheet, array $headers, int $dataRowCount, string $reportTitle = ''): void
{
    $colCount   = count($headers);
    $lastColLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

    // ── Insert title row above the header row ────────────────────────────────
    $sheet->insertNewRowBefore(1, 1);

    // Merge A1 across all columns and write title text
    $titleCell  = 'A1:' . $lastColLtr . '1';
    $sheet->mergeCells($titleCell);
    $label = 'PSPF Helpdesk CRM';
    if ($reportTitle !== '') {
        $label .= '  |  ' . $reportTitle;
    }
    $label .= '  |  Generated: ' . date('d M Y, H:i');
    $sheet->setCellValue('A1', $label);

    $sheet->getStyle($titleCell)->applyFromArray([
        'font' => [
            'bold'  => true,
            'size'  => 13,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '00274D'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
        ],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(28);

    // ── Header row (now row 2 after insert) ──────────────────────────────────
    $headerRange = 'A2:' . $lastColLtr . '2';
    $sheet->getStyle($headerRange)->applyFromArray([
        'font' => [
            'bold'  => true,
            'size'  => 10,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '1F5C99'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical'   => Alignment::VERTICAL_CENTER,
            'wrapText'   => true,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['rgb' => 'FFFFFF'],
            ],
        ],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(20);

    // ── Data rows (rows 3 … 2+dataRowCount) ─────────────────────────────────
    $evenFill = 'DCE6F1'; // soft blue
    $oddFill  = 'FFFFFF'; // white

    for ($r = 3; $r <= 2 + $dataRowCount; $r++) {
        $range = 'A' . $r . ':' . $lastColLtr . $r;
        $fill  = ($r % 2 === 0) ? $evenFill : $oddFill;

        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $fill],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'BDD7EE'],
                ],
            ],
        ]);
    }

    // ── Outer border around the entire table ────────────────────────────────
    if ($dataRowCount > 0) {
        $tableRange = 'A2:' . $lastColLtr . (2 + $dataRowCount);
        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color'       => ['rgb' => '1F5C99'],
                ],
            ],
        ]);
    }

    // ── Sheet background (unused cells): very light grey ────────────────────
    $sheet->getDefaultRowDimension()->setRowHeight(16);

    // ── Freeze panes below the header row ───────────────────────────────────
    $sheet->freezePane('A3');

    // ── Column widths: auto-size up to a max, then wrap text ────────────────
    // Columns wider than $maxWidth characters get capped and wrap their text.
    $maxWidth = 40;
    for ($c = 1; $c <= $colCount; $c++) {
        $col = $sheet->getColumnDimensionByColumn($c);
        $col->setAutoSize(true);
    }
    // Force a calculation pass so getWidth() returns real values
    $sheet->calculateColumnWidths();
    for ($c = 1; $c <= $colCount; $c++) {
        $colLtr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $col    = $sheet->getColumnDimension($colLtr);
        if ($col->getWidth() > $maxWidth) {
            $col->setAutoSize(false);
            $col->setWidth($maxWidth);
            // Apply wrap text to every data cell in this column (rows 3+)
            if ($dataRowCount > 0) {
                $sheet->getStyle($colLtr . '3:' . $colLtr . (2 + $dataRowCount))
                      ->getAlignment()->setWrapText(true);
            }
        }
    }

    // ── Sheet tab colour ────────────────────────────────────────────────────
    $sheet->getTabColor()->setRGB('1F5C99');
}
