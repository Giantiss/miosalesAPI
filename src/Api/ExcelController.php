<?php

namespace App\Api;

use App\Config\Database; // Import the Database class
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $this->db = $db->getConnection(); // Get the database connection from the Database class
        $this->token = $token;

        if (!$this->db) {
            throw new Exception("Database connection failed.");
        }
    }

    public function importExcel($filePath, $batchSize = 100)
    {
        // Logger function for debugging
        function log_message($message)
        {
            file_put_contents('../logs/database_insert.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
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
            log_message(__METHOD__.'| File exists and MIME type is valid: ' . $filePath);

            // Load the spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            log_message(__METHOD__.'|Spreadsheet loaded successfully');
            $sheet = $spreadsheet->getActiveSheet();
            $sheetData = $sheet->toArray(null, true, true, true);

            $highestRow = $sheet->getHighestRow(); // Get the actual last row with data
            $totalRows = $highestRow - 1; // Exclude header row

            $rowsInserted = 0;
            $batchData = [];

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
                $service_name = $row['D']; // Item column (use this to find service ID)
                $amount = $this->parseAmount($row['Q']); // Total sales column
                $net = $amount * 0.86; // Net sales column, adjusted to 86%

                // Basic validation for required fields
                if (empty($reciept_no) || empty($stylist_name) || empty($service_name) || empty($amount)) {
                    $response['message'] = "Missing required fields in row $rowIndex.";
                    return $response;
                }

                // Find stylist ID based on stylist name
                try {
                    $stylistID = $this->getStylistIDByName($stylist_name);
                } catch (Exception $e) {
                    $response['message'] = $e->getMessage(); // Error message from getStylistIDByName
                    return $response;
                }

                // Find service ID based on service name
                $serviceID = $this->getServiceIDByName($service_name);

                // Prepare data for insertion
                $rowData = [
                    'entry_date' => $entry_date,
                    'reciept_no' => $reciept_no,
                    'stylist' => $stylistID,
                    'service' => $serviceID,
                    'amount' => $amount,
                    'net' => $net,
                    'spa_transaction' => null, // Assuming you have a logic for this value
                    'expunged' => 0, // Default value if no logic
                    'v_account' => null // Assuming you have a logic for this value
                ];

                // Add row data to batch
                $batchData[] = $rowData;

                // Insert batch if batch size is reached
                if (count($batchData) >= $batchSize) {
                    $this->insertBatch($batchData);
                    $rowsInserted += count($batchData);
                    $batchData = []; // Reset batch data
                }
            }

            // Insert any remaining rows in the batch
            if (!empty($batchData)) {
                $this->insertBatch($batchData);
                $rowsInserted += count($batchData);
            }

            $response = ['status' => 'success', 'rowsInserted' => $rowsInserted];
        } catch (PDOException $e) {
            $response['message'] = 'Database error occurred: ' . $e->getMessage();
            $this->logError($e->getMessage());
        } catch (Exception $e) {
            $response['message'] = 'An error occurred while processing the file: ' . $e->getMessage();
            $this->logError($e->getMessage());
        }

        return $response;
    }

    private function insertBatch($batchData)
    {
        try {
            // Ensure $batchData is valid
            if (empty($batchData)) {
                throw new Exception("Invalid or empty data provided for batch insert.");
            }

            // Log the data being inserted to verify it
            // error_log(__METHOD__."| Inserting Batch: " . print_r($batchData, true), 3, __DIR__ . '/../../logs/debug.log');
            log_message('Batch size: ' . count($batchData));
            $startTime = microtime(true);
        


            // Prepare the query with placeholders for multiple rows
            $query = "INSERT INTO service_sheet (entry_date, reciept_no, stylist, `service`, amount, net, 
            spa_transaction, expunged
            ) VALUES ";
            $placeholders = [];
            $params = [];

            foreach ($batchData as $rowData) {
                $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?)";
                $params = array_merge($params, [
                    $rowData['entry_date'],
                    $rowData['reciept_no'],
                    $rowData['stylist'],
                    $rowData['service'],
                    $rowData['amount'],
                    $rowData['net'],
                    $rowData['spa_transaction'],
                    $rowData['expunged'],
                ]);
            }

            $query .= implode(", ", $placeholders);

            // Log the query and parameters to verify they are being passed correctly
            // error_log("Executing Query: $query with Params: " . print_r($params, true), 3, __DIR__ . '/../../logs/debug.log');

            // Execute the insert query
            $stmt = $this->db->prepare($query);
            $response= $stmt->execute($params);
            

            // Check if the query was successful
            log_message('Batch insert response: ' . $response);
            log_message('Rows inserted: ' . $stmt->rowCount());


            // Commit the transaction
         //   $this->db->commit();
            $endTime = microtime(true);
            log_message('Batch insert took ' . round($endTime - $startTime, 4) . ' seconds');
        } catch (PDOException $e) {
            // Log the error and roll back the transaction
            
            $this->logError(__METHOD__."| Database batch insert failed: " . $e->getMessage());
            throw new Exception("Database batch insert failed: " . $e->getMessage());
        } catch (Exception $e) {
            // Catch any other exceptions and roll back
            $this->db->rollBack();
            $this->logError("Error: " . $e->getMessage());
            throw new Exception("Error: " . $e->getMessage());
        }
    }

    private function getStylistIDByName($stylist_name)
    {
        $stmt = $this->db->prepare("SELECT id FROM stylists WHERE name = ?");
        $stmt->execute([$stylist_name]);
        $stylistID = $stmt->fetchColumn();

        // If no stylist ID is found, throw an exception to stop the process
        if ($stylistID === false) {
            throw new Exception("Stylist with name '$stylist_name' not found in the database.");
        }

        return $stylistID;
    }

    private function getServiceIDByName($service_name)
    {
        $stmt = $this->db->prepare("SELECT id FROM services WHERE item = ?");
        $stmt->execute([$service_name]);
        $serviceID = $stmt->fetchColumn();

        // Return the fetched ID, or default to 5000 if no ID is found
        return $serviceID !== false ? $serviceID : 5000;
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
