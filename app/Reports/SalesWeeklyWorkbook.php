<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The weekly summary sales report as a worksheet, laid out like the printed one:
 * a title, the period, then one block per day and a block with the totals.
 */
final class SalesWeeklyWorkbook
{
    private const TYPE_LABEL = ['DINE IN' => 'Dine In', 'GOJEK' => 'Gojek', 'GRAB' => 'Grab', 'TAKE AWAY' => 'Takeaway', 'EVENT/CATERING' => 'Event/Catering'];

    private const GREY = 'FFD9D9D9';

    /**
     * @param  array{from: string, to: string, types: list<string>, days: list<array>, total: array}  $report  as returned by SalesWeeklyReportController
     */
    public static function make(string $clientName, array $report, CarbonImmutable $generatedAt): Spreadsheet
    {
        $types = $report['types'];
        $count = count($types);

        // Columns: label | cover (types, TOTAL) | revenue (types, TOTAL) | Food, Beverage, F & B, OTHERS, Average
        $coverFrom = 2;
        $coverTotal = $coverFrom + $count;
        $revenueFrom = $coverTotal + 1;
        $revenueTotal = $revenueFrom + $count;
        $foodCol = $revenueTotal + 1;
        $lastCol = $foodCol + 4;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Weekly Summary Sales');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $letter = fn (int $col) => Coordinate::stringFromColumnIndex($col);
        $last = $letter($lastCol);

        // Title
        $sheet->setCellValue('A1', mb_strtoupper($clientName));
        $sheet->setCellValue('A2', 'WEEKLY SUMMARY SALES REPORT');
        foreach ([1, 2] as $row) {
            $sheet->mergeCells("A{$row}:{$last}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle("A2:{$last}2")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);

        // Period
        $sheet->setCellValue('A4', 'From :');
        $sheet->setCellValue('B4', CarbonImmutable::parse($report['from'])->format('F d, Y').' 00:00');
        $sheet->setCellValue('A5', 'To :');
        $sheet->setCellValue('B5', CarbonImmutable::parse($report['to'])->format('F d, Y').' 23:59');
        $sheet->getStyle('A4:A5')->getFont()->setBold(true);
        $sheet->getStyle('A4:A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->setCellValue("{$last}4", 'Report time : '.$generatedAt->format('l d F Y H:i'));
        $sheet->getStyle("{$last}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $row = 7;

        foreach ($report['days'] as $day) {
            if ($day['rows'] === []) {
                continue; // a day without sales has no block, as in the printed report
            }

            $row = self::block($sheet, $row, trim($day['date'].' '.substr((string) ($day['synced_at'] ?? ''), 11)), $day['rows'], $types, [$coverFrom, $coverTotal, $revenueFrom, $revenueTotal, $foodCol, $lastCol]);
        }

        self::block($sheet, $row, 'TOTAL', $report['total']['rows'], $types, [$coverFrom, $coverTotal, $revenueFrom, $revenueTotal, $foodCol, $lastCol]);

        $sheet->getColumnDimension('A')->setWidth(28);
        for ($col = $coverFrom; $col <= $lastCol; $col++) {
            $sheet->getColumnDimension($letter($col))->setWidth($col === $lastCol ? 22 : 14);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->setShowGridlines(false);

        return $spreadsheet;
    }

    /**
     * One block: its title cell, the two header rows and a row per shift plus TOTAL. Returns the next free row.
     *
     * @param  list<array>  $rows
     * @param  list<string>  $types
     * @param  array{int, int, int, int, int, int}  $cols  coverFrom, coverTotal, revenueFrom, revenueTotal, foodCol, lastCol
     */
    private static function block(Worksheet $sheet, int $top, string $title, array $rows, array $types, array $cols): int
    {
        [$coverFrom, $coverTotal, $revenueFrom, $revenueTotal, $foodCol, $lastCol] = $cols;
        $letter = fn (int $col) => Coordinate::stringFromColumnIndex($col);
        $sub = $top + 1;

        // Title cell spans both header rows
        $sheet->setCellValue("A{$top}", $title);
        $sheet->mergeCells("A{$top}:A{$sub}");

        foreach ([[$coverFrom, $coverTotal, 'COVER / PAX'], [$revenueFrom, $revenueTotal, 'REVENUE'], [$foodCol, $lastCol, 'REVENUE / DEPARTMENT']] as [$from, $to, $label]) {
            $sheet->setCellValue($letter($from).$top, $label);
            $sheet->mergeCells($letter($from).$top.':'.$letter($to).$top);
        }

        foreach ([$coverFrom, $revenueFrom] as $start) {
            foreach ($types as $i => $type) {
                $sheet->setCellValue($letter($start + $i).$sub, self::TYPE_LABEL[$type] ?? $type);
            }
            $sheet->setCellValue($letter($start + count($types)).$sub, 'TOTAL');
        }
        foreach (['Food', 'Beverage', 'F & B', 'OTHERS', 'Average Check / Pax'] as $i => $label) {
            $sheet->setCellValue($letter($foodCol + $i).$sub, $label);
        }

        // Grey cells: the TOTAL columns and the average
        foreach ([$coverTotal, $revenueTotal, $lastCol] as $col) {
            $sheet->getStyle($letter($col).$sub)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::GREY);
        }

        $headerStyle = $sheet->getStyle("A{$top}:".$letter($lastCol).$sub);
        $headerStyle->getFont()->setBold(false);
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A{$top}:".$letter($lastCol)."{$top}")->getFont()->setBold(true);

        // Data rows
        $row = $sub + 1;

        foreach ($rows as $r) {
            $values = [$r['label']];
            foreach ($types as $type) {
                $values[] = $r['cover'][$type];
            }
            $values[] = $r['cover']['total'];
            foreach ($types as $type) {
                $values[] = $r['revenue'][$type];
            }
            $values[] = $r['revenue']['total'];
            array_push($values, $r['food'], $r['beverage'], $r['fnb'], $r['others'], $r['average_check']);

            foreach ($values as $i => $value) {
                $sheet->setCellValue($letter($i + 1).$row, $value);
            }

            $row++;
        }

        $body = $sheet->getStyle("B".($sub + 1).':'.$letter($lastCol).($row - 1));
        $body->getNumberFormat()->setFormatCode('#,##0');
        $body->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A'.($sub + 1).':A'.($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Grid lines around everything in the block
        $sheet->getStyle("A{$top}:".$letter($lastCol).($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        return $row + 1; // one blank row between blocks
    }
}
