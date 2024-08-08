<?php
namespace App\Utils;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelHandler
{
    public static function importExcel($filePath)
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        return $sheetData;
    }

    public static function exportExcel($data, $filePath)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        foreach ($data as $rowNumber => $row) {
            foreach ($row as $columnNumber => $cellValue) {
                $sheet->setCellValueByColumnAndRow($columnNumber + 1, $rowNumber + 1, $cellValue);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
    }
}
