<?php

namespace App\Api;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PDO;
use PDOException;
use DateTime;
use Exception;

class ExcelController
{
    private $db;
    private $token;

    public function __construct($db, $token)
    {
        $this->db = $db;
        $this->token = $token;
    }

    public function importExcel($filePath)
    {
        $response = ['status' => 'error', 'message' => 'Unknown error'];

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

            $rowsInserted = 0;
            foreach ($sheetData as $rowIndex => $row) {
                if ($rowIndex === 1) {
                    continue; // Skip the header row
                }

                $entry_date = $this->parseDate($row['F']);
                if ($entry_date === false) {
                    error_log("Date parsing failed for value: " . $row['F'], 3, __DIR__ . '/../../logs/debug.log');
                    continue;
                }

                $billNumber = $row['A'];
                $stylist = $row['J'];
                $service = $row['I'];
                $amount = $this->parseAmount($row['Q']);
                $net = $amount * 0.86;

                $this->insertRow($entry_date, $stylist, $service, $amount, $net, $billNumber);
                $rowsInserted++;
            }

            $response = ['status' => 'success', 'rowsInserted' => $rowsInserted];
        } catch (PDOException $e) {
            $response['message'] = 'Database error occurred';
            $this->logError($e->getMessage());
        } catch (Exception $e) {
            $response['message'] = 'An error occurred while processing the file';
            $this->logError($e->getMessage());
        }

        return $response;
    }

    public function exportExcel($filePath)
    {
        $response = ['status' => 'error', 'message' => 'Unknown error'];

        try {
            // Create a new Spreadsheet object
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Set the headers for the spreadsheet
            $sheet->setCellValue('A1', 'Bill Number');
            $sheet->setCellValue('B1', 'Entry Date');
            $sheet->setCellValue('C1', 'Stylist');
            $sheet->setCellValue('D1', 'Service');
            $sheet->setCellValue('E1', 'Amount');
            $sheet->setCellValue('F1', 'Net');

            // Retrieve data from the database
            $stmt = $this->db->query("SELECT BillNumber, entry_date, stylist, service, amount, net FROM miosales");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Populate the spreadsheet with database data
            $rowIndex = 2; // Start from the second row after the headers
            foreach ($data as $row) {
                $sheet->setCellValue('A' . $rowIndex, $row['BillNumber']);
                $sheet->setCellValue('B' . $rowIndex, $row['entry_date']);
                $sheet->setCellValue('C' . $rowIndex, $row['stylist']);
                $sheet->setCellValue('D' . $rowIndex, $row['service']);
                $sheet->setCellValue('E' . $rowIndex, $row['amount']);
                $sheet->setCellValue('F' . $rowIndex, $row['net']);
                $rowIndex++;
            }

            // Write the spreadsheet to a file
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');

            // Ensure the directory exists and is writable
            $directory = dirname($filePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            $writer->save($filePath);

            $response = ['status' => 'success', 'message' => 'File Downloaded Successfully'];
        } catch (PDOException $e) {
            $response['message'] = 'Database error occurred';
            $this->logError($e->getMessage());
        } catch (Exception $e) {
            $response['message'] = 'An error occurred while downloading the file';
            $this->logError($e->getMessage());
        }

        return $response;
    }



    private function parseDate($dateValue)
    {
        try {
            $parsedDate = DateTime::createFromFormat('d M Y', $dateValue);
            if ($parsedDate === false) {
                throw new Exception("Invalid date format");
            }
            return $parsedDate->format('Y-m-d');
        } catch (Exception $e) {
            $this->logError("Date parsing failed for value: " . $dateValue);
            return false;
        }
    }

    private function parseAmount($amount)
    {
        return floatval(str_replace(',', '', $amount));
    }

    private function insertRow($entry_date, $stylist, $service, $amount, $net, $billNumber)
    {
        try {
            $query = "INSERT INTO miosales (entry_date, stylist, service, amount, net, BillNumber) VALUES (:entry_date, :stylist, :service, :amount, :net, :BillNumber)";
            $stmt = $this->db->prepare($query);

            // Bind parameters
            $stmt->bindParam(':entry_date', $entry_date);
            $stmt->bindParam(':stylist', $stylist);
            $stmt->bindParam(':service', $service);
            $stmt->bindParam(':amount', $amount);
            $stmt->bindParam(':net', $net);
            $stmt->bindParam(':BillNumber', $billNumber);

            $stmt->execute();
        } catch (PDOException $e) {
            $this->logError("Database insert failed: " . $e->getMessage());
            throw new Exception("Database insert failed: " . $e->getMessage());
        }
    }

    private function logError($message)
    {
        error_log($message, 3, __DIR__ . '/../../logs/debug.log');
    }
}
?>
