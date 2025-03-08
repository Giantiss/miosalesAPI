<?php

namespace App\Api;

use App\Config\Database; // Import the Database class
use PhpOffice\PhpSpreadsheet\IOFactory;
use PDO;
use PDOException;
use DateTime;
use Exception;

error_reporting(E_ALL);

class ExcelController
{
    private $db;
    private $token;

    public function __construct(Database $db, $token)
    {
        try {
            $this->db = $db->getConnection(); // Get the database connection from the Database class
            $this->token = $token;

            // Log the connection status
            if ($this->db) {
                error_log("Database connection established.", 3, __DIR__ . '/../../logs/debug.log');
            } else {
                $errorInfo = $this->db ? $this->db->errorInfo() : ['No connection'];
                $errorMessage = "Database connection failed: " . implode(' ', $errorInfo);
                error_log($errorMessage, 3, __DIR__ . '/../../logs/debug.log');
                throw new Exception($errorMessage);
            }
        } catch (Exception $e) {
            error_log($e->getMessage(), 3, __DIR__ . '/../../logs/debug.log');
            throw $e;
        }
    }

    public function importExcel($filePath, $batchSize = 100)
    {
        // Logger function for debugging
        function log_message($message)
        {
            $logDir = __DIR__ . '/../../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            file_put_contents($logDir . '/database_insert.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
        }

        log_message('Starting importExcel method');

        $response = ['status' => 'error', 'message' => 'Unknown error'];
        $fileName = basename($filePath); // Get the file name
        $totalRows = 0;
        $progressId = 0;

        // Check if file exists
        if (!file_exists($filePath)) {
            $response['message'] = 'File not found.';
            return $response;
        }

        // MIME type check
        $mimeType = mime_content_type($filePath);
        log_message('Detected MIME type: ' . $mimeType);

        // Allowed MIME types
        $allowedMimeTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
            'application/vnd.ms-excel' // .xls
        ];

        // Check MIME type instead of file extension
        if (!in_array($mimeType, $allowedMimeTypes)) {
            $response['message'] = 'Invalid file type. Only .xlsx and .xls files are allowed.';
            return $response;
        }

        try {
            log_message(__METHOD__ . '| File exists and MIME type is valid: ' . $filePath);

            // Load the spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            log_message(__METHOD__ . '|Spreadsheet loaded successfully');
            $sheet = $spreadsheet->getActiveSheet();
            $sheetData = $sheet->toArray(null, true, true, true);

            $highestRow = $sheet->getHighestRow(); // Get the actual last row with data
            $totalRows = $highestRow - 1; // Exclude header row

            $rowsInserted = 0;
            $batchData = [];

            // Create a temporary table
            $this->createTemporaryTable();

            foreach ($sheetData as $rowIndex => $row) {
                if ($rowIndex === 1) {
                    continue; // Skip the header row
                }

                // Skip rows beyond the actual last populated row
                if ($rowIndex > $highestRow) {
                    break;
                }

                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Parse the date
                $entry_date = $this->parseDate($row['A']); // Date column
                if ($entry_date === false) {
                    $response['message'] = "Invalid date format in row $rowIndex.";
                    return $response;
                }

                // Get the values from the row
                $reciept_no = $row['B']; // Sale no. column
                $stylist_name = $row['H']; // Team member column (use this to find stylist ID)
                $service_name = $row['E']; // Item column (use this to find service ID)
                $amount = $this->parseAmount($row['Q']); // Total sales column
                $net = $amount * 0.86; // Net sales column, adjusted to 86%

                // Basic validation for required fields
                if (empty($reciept_no) || empty($stylist_name) || empty($service_name) || empty($amount)) {
                    $response['message'] = "Missing required fields in row $rowIndex.";
                    return $response;
                }

                // Prepare data for insertion into temporary table
                $rowData = [
                    'entry_date' => $entry_date,
                    'reciept_no' => $reciept_no,
                    'stylist_name' => $stylist_name,
                    'service_name' => $service_name,
                    'amount' => $amount,
                    'net' => $net
                ];

                // Add row data to batch
                $batchData[] = $rowData;

                // Insert batch if batch size is reached
                if (count($batchData) >= $batchSize) {
                    $this->insertTemporaryBatch($batchData);
                    $batchData = []; // Reset batch data
                }
            }

            // Insert any remaining rows in the batch
            if (!empty($batchData)) {
                $this->insertTemporaryBatch($batchData);
            }

            // Check for duplicate receipt numbers using MySQL joins
            $duplicateReceipts = $this->checkDuplicateReceipts();
            if (!empty($duplicateReceipts)) {
                $response['message'] = 'Duplicate receipt numbers found: ' . implode(', ', (array)$duplicateReceipts);
                return $response;
            }

            // Validate stylists and services using MySQL joins
            $invalidStylists = $this->checkInvalidStylists();
            if (!empty($invalidStylists)) {
                $response['message'] = 'Invalid stylists found: ' . implode(', ', $invalidStylists) . '. Please add these stylists to the database and try again.';
                $response['invalidStylists'] = $invalidStylists;
                $response['invalidStylistsCount'] = count($invalidStylists);
                return $response;
            }

            $invalidServices = $this->checkInvalidServices();
            if (!empty($invalidServices)) {
                $response['message'] = 'Invalid services found: ' . implode(', ', $invalidServices) . '. Please add these services to the database and try again.';
                $response['invalidServices'] = $invalidServices;
                $response['invalidServicesCount'] = count($invalidServices);
                return $response;
            }

            // Update invalid services to 'new-service'
            $this->updateInvalidServices();

            // Insert data from temporary table to service_sheet table
            $this->insertFromTemporaryTable();
            $rowsInserted = $this->getTemporaryTableRowCount();
            $totalAmount = $this->getTotalAmountFromTemporaryTable();

            $response = [
                'status' => 'success',
                'rowsInserted' => $rowsInserted,
                'totalAmount' => $totalAmount
            ];
        } catch (PDOException $e) {
            $response['message'] = 'Database error occurred: ' . $e->getMessage() . '. Please contact support.';
            $this->logError($e->getMessage());
            return $response; // Return the response immediately on error
        } catch (Exception $e) {
            $response['message'] = 'An error occurred while processing the file: ' . $e->getMessage() . '. Please contact support.';
            $this->logError($e->getMessage());
            return $response; // Return the response immediately on error
        }

        return $response;
    }

    private function createTemporaryTable()
    {
        $query = "CREATE TEMPORARY TABLE temp_service_sheet (
            entry_date DATE,
            reciept_no VARCHAR(255),
            stylist_name VARCHAR(255),
            service_name VARCHAR(255),
            amount DECIMAL(10, 2),
            net DECIMAL(10, 2)
        )";
        $this->db->exec($query);
    }

    private function insertTemporaryBatch($batchData)
    {
        try {
            // Ensure $batchData is valid
            if (empty($batchData)) {
                throw new Exception("Invalid or empty data provided for batch insert.");
            }

            // Log the data being inserted to verify it
            log_message('Batch size: ' . count($batchData));
            $startTime = microtime(true);


            // Prepare the query with placeholders for multiple rows
            $query = "INSERT INTO temp_service_sheet (entry_date, reciept_no, stylist_name, service_name, amount, net) VALUES ";
            $placeholders = [];
            $params = [];

            foreach ($batchData as $rowData) {
                $placeholders[] = "(?, ?, ?, ?, ?, ?)";
                $params = array_merge($params, [
                    $rowData['entry_date'],
                    $rowData['reciept_no'],
                    $rowData['stylist_name'],
                    $rowData['service_name'],
                    $rowData['amount'],
                    $rowData['net']
                ]);
            }

            $query .= implode(", ", $placeholders);

            // Execute the insert query
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);

            // Check if the query was successful
            log_message('Batch insert response: ' . $stmt->rowCount());
            $endTime = microtime(true);
            log_message('Batch insert took ' . round($endTime - $startTime, 4) . ' seconds');
        } catch (PDOException $e) {
            // Log the error and roll back the transaction
            $this->logError(__METHOD__ . "| Database batch insert failed: " . $e->getMessage());
            throw new Exception("Database batch insert failed: " . $e->getMessage());
        } catch (Exception $e) {
            // Catch any other exceptions and roll back
            $this->db->rollBack();
            $this->logError("Error: " . $e->getMessage());
            throw new Exception("Error: " . $e->getMessage());
        }
    }

    private function checkDuplicateReceipts()
    {
        $query = "SELECT temp_service_sheet.reciept_no FROM temp_service_sheet
                  JOIN service_sheet ON temp_service_sheet.reciept_no = service_sheet.reciept_no";
        $stmt = $this->db->query($query);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function checkInvalidStylists()
    {
        $query = "SELECT DISTINCT stylist_name FROM temp_service_sheet
                  LEFT JOIN stylists ON LOWER(temp_service_sheet.stylist_name) = LOWER(stylists.name)
                  WHERE stylists.id IS NULL";
        $stmt = $this->db->query($query);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function checkInvalidServices()
    {
        $query = "SELECT DISTINCT service_name FROM temp_service_sheet
                  LEFT JOIN services ON LOWER(temp_service_sheet.service_name) = LOWER(services.item)
                  WHERE services.id IS NULL";
        $stmt = $this->db->query($query);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function insertFromTemporaryTable()
    {
        // Insert data from the temporary table into the service_sheet table
        $query = "INSERT INTO service_sheet
                  (`entryId`, `entry_date`, `stylist`, `service`, `amount`, `net`, `reciept_no`)
                  SELECT null, `entry_date`, st.id, sv.id, `amount`, `net`, `reciept_no`
                  FROM `temp_service_sheet` s
                  INNER JOIN stylists st ON st.name = s.stylist_name
                  INNER JOIN services sv ON s.service_name = sv.item";
        $this->db->exec($query);
    }

    private function updateInvalidServices()
    {
        // Update invalid services to 'new-service'
        $query = "UPDATE `temp_service_sheet` s
                  LEFT JOIN services sv ON s.service_name = sv.item
                  SET s.service_name = 'new-service'
                  WHERE sv.item IS NULL";
        $this->db->exec($query);
    }

    private function getTemporaryTableRowCount()
    {
        $query = "SELECT COUNT(*) FROM temp_service_sheet";
        return $this->db->query($query)->fetchColumn();
    }

    private function getTotalAmountFromTemporaryTable()
    {
        $query = "SELECT SUM(amount) FROM temp_service_sheet";
        return $this->db->query($query)->fetchColumn();
    }

    private function parseDate($dateValue)
    {
        try {
            // Attempt to parse with two-digit year and time format
            $parsedDate = DateTime::createFromFormat('d/m/y H:i:s', $dateValue);

            // Check if parsing failed, then try with four-digit year
            if ($parsedDate === false) {
                $parsedDate = DateTime::createFromFormat('d/m/Y H:i:s', $dateValue);
            }

            // If both parsing attempts fail, throw an exception
            if ($parsedDate === false) {
                throw new Exception("Invalid date format");
            }

            // Return the date in 'Y-m-d' format
            return $parsedDate->format('Y-m-d');
        } catch (Exception $e) {
            // Log the error and return false if the date is invalid
            $this->logError("Date parsing failed: " . $e->getMessage());
            return false;
        }
    }

    private function parseAmount($amount)
    {
        return floatval(str_replace(',', '', $amount));
    }

    private function logError($message)
    {
        error_log($message, 3, __DIR__ . '/../../logs/debug.log');
    }
}
