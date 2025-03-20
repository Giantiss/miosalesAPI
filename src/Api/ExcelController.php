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
    private $batchSize;  // Add this line

    public function __construct(Database $db, $token)
    {
        try {
            $this->db = $db->getConnection(); // Get the database connection from the Database class
            $this->token = $token;
            $this->batchSize = 500;  // Add this line

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

    public function logDebug($message, $method=null, $line=null){

                    $logDir = __DIR__ . '/../../logs';
                    if (!is_dir($logDir)) {
                        mkdir($logDir, 0777, true);
                    }
                    file_put_contents($logDir . '/database_insert.log',
                     date('Y-m-d H:i:s') . ' - ' .$method. ' - line ' .$line. '|' . $message . PHP_EOL, FILE_APPEND);
                
        
    }
    public function importExcel($filePath, $batchSize = 100)
    {


        $this->logDebug('Starting importExcel method');
        set_time_limit(300); // Set timeout to 5 minutes for this method

        $response = ['status' => 'error', 'message' => 'Unknown error'];
        $fileName = basename($filePath); // Get the file name
        $totalRows = 0;
        $processedRows = 0;
        $progressId = 0;

        // Generate a unique upload ID
        $uploadId = uniqid();

        // Check if file exists
        if (!file_exists($filePath)) {
            $response['message'] = 'File not found.';
            return $response;
        }

        // MIME type check
        $mimeType = mime_content_type($filePath);
        $this->logDebug('Detected MIME type: ' . $mimeType);

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
            $this->logDebug(__METHOD__ . '| File exists and MIME type is valid: ' . $filePath);

            // Load the spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $this->logDebug(__METHOD__ . '|Spreadsheet loaded successfully');
            $sheet = $spreadsheet->getActiveSheet();
            $sheetData = $sheet->toArray(null, true, true, true);

            $highestRow = $sheet->getHighestRow(); // Get the actual last row with data
            $totalRows = $highestRow - 1; // Exclude header row


            $rowsInserted = 0;
            $batchData = [];

            // Create the table if it doesn't exist
            $this->createTable();

            // Check for duplicate receipt numbers first
            $duplicateReceipts = $this->checkDuplicateReceiptsBeforeInsert($sheetData);
            if (!empty($duplicateReceipts)) {
                $response['status'] = 'error';
                $response['message'] = count($duplicateReceipts) . ' Duplicate Receipt Numbers Found. Please Review The file And Try Again.';
                return $response;
            }

            // Store the validated data in a temporary file for later processing
            $tempData = [
                'upload_id' => $uploadId,
                'file_path' => $filePath,
                'total_rows' => $totalRows,
                'status' => 'validated'
            ];

            $tempDir = __DIR__ . '/../../src/temp/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            
            file_put_contents($tempDir . $uploadId . '.json', json_encode($tempData));

            $response = [
                'status' => 'success',
                'message' => 'File validated successfully',
                'upload_id' => $uploadId
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

    private function createTable()
    {
        $query = "CREATE TABLE IF NOT EXISTS service_sheet_uploads (
            upload_id VARCHAR(255),
            entry_date DATE,
            reciept_no VARCHAR(255),
            stylist_name VARCHAR(255),
            service_name VARCHAR(255),
            amount DECIMAL(10, 2),
            net DECIMAL(10, 2)
        )";
        $this->db->exec($query);
    }


    private function insertBatch($batchData)
    {
        try {
            $this->db->beginTransaction();
            $this->logDebug('Starting batch insert', ['batch_size' => count($batchData)]);
            
            // Ensure $batchData is valid
            if (empty($batchData)) {
                throw new Exception("Invalid or empty data provided for batch insert.");
            }

            // Log the data being inserted to verify it
            $this->logDebug('Batch size: ' . count($batchData));
            $startTime = microtime(true);

            // Prepare the query with placeholders for multiple rows
            $query = "INSERT INTO service_sheet_uploads (upload_id, entry_date, reciept_no, stylist_name, service_name, amount, net) VALUES ";
            $placeholders = [];
            $params = [];

            foreach ($batchData as $rowData) {
                $placeholders[] = "(?, ?, ?, ?, ?, ?, ?)";
                $params = array_merge($params, [
                    $rowData['upload_id'],
                    $rowData['entry_date'],
                    $rowData['reciept_no'],
                    $rowData['stylist_name'],
                    $rowData['service_name'],
                    $rowData['amount'],
                    $rowData['net']
                ]);
            }

            $query .= implode(", ", $placeholders);
            $this->logDebug('Executing SQL', [
                'query' => $query,
                'param_count' => count($params)
            ]);

            // Execute the insert query
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);

            // Check if the query was successful
            $this->logDebug('Batch insert response: ' . $stmt->rowCount());
            $endTime = microtime(true);
            $this->logDebug('Batch insert took ' . round($endTime - $startTime, 4) . ' seconds');
            $this->logDebug('Batch insert completed', [
                'rows_affected' => $stmt->rowCount(),
                'success' => $stmt->rowCount() > 0
            ]);
            
            $this->db->commit();
            $this->logDebug('Transaction committed');
        } catch (PDOException $e) {
            // Log the error and roll back the transaction
            $this->logError(__METHOD__ . "| Database batch insert failed: " . $e->getMessage());
            throw new Exception("Database batch insert failed: " . $e->getMessage());
        } catch (Exception $e) {
            // Catch any other exceptions and roll back
            $this->db->rollBack();
            $this->logDebug('Batch insert failed', [
                'error' => $e->getMessage(),
                'sql_error' => $this->db->errorInfo()
            ], $e->getTrace());
            throw new Exception("Error: " . $e->getMessage());
        }
    }

    private function checkDuplicateReceipts($uploadId) {
        $this->logDebug('Checking for duplicates', ['upload_id' => $uploadId], __METHOD__, __LINE__);
        try {
            $this->logDebug('Checking for duplicate receipts for upload_id: ' . $uploadId);
            
            // Use a join to check for duplicate receipts in one query
            $query = "SELECT u.reciept_no 
                      FROM service_sheet_uploads u
                      INNER JOIN service_sheet s ON u.reciept_no = s.reciept_no
                      WHERE u.upload_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$uploadId]);
            $duplicates = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $this->logDebug('Found ' . count($duplicates) . ' duplicate receipts');
            
            if (!empty($duplicates)) {
                $this->logError('Duplicate receipts found: ' . implode(', ', $duplicates));
            }
            
            $this->logDebug('Duplicate check results', [
                'upload_id' => $uploadId,
                'duplicates_found' => count($duplicates),
                'duplicate_list' => $duplicates
            ]);
            
            return $duplicates;
        } catch (Exception $e) {
            $this->logError('Error checking duplicates: ' . $e->getMessage(), $e->getTrace());
            throw $e;
        }
    }

    private function checkInvalidStylists($uploadId)
    {
        $query = "SELECT DISTINCT stylist_name FROM service_sheet_uploads
                  LEFT JOIN stylists ON LOWER(service_sheet_uploads.stylist_name) = LOWER(stylists.name)
                  WHERE stylists.id IS NULL AND service_sheet_uploads.upload_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$uploadId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }


    private function insertFromTable($uploadId)
    {
        try {
            $this->logDebug('Starting insertFromTable', ['upload_id' => $uploadId],__METHOD__,__LINE__);
            
            // First verify we have records to insert
            $countQuery = "SELECT COUNT(*) FROM service_sheet_uploads WHERE upload_id = ?";
            $stmt = $this->db->prepare($countQuery);
            $stmt->execute([$uploadId]);
            $recordCount = $stmt->fetchColumn();
            
            $this->logDebug('Records found in uploads table', ['count' => $recordCount]);
            
            if ($recordCount === 0) {
                throw new Exception("No records found to insert for upload_id: $uploadId");
            }

            // Perform the actual insert
            $query = "INSERT INTO service_sheet
                      (`entryId`, `entry_date`, `stylist`, `service`, `amount`, `net`, `reciept_no`)
                      SELECT null, `entry_date`, st.id, sv.id, `amount`, `net`, `reciept_no`
                      FROM `service_sheet_uploads` s
                      INNER JOIN stylists st ON LOWER(st.name) = LOWER(s.stylist_name)
                      INNER JOIN services sv ON LOWER(sv.item) = LOWER(s.service_name)
                      WHERE s.upload_id = ?";
            
            $this->db->beginTransaction();
            $stmt = $this->db->prepare($query);
            $stmt->execute([$uploadId]);
            $insertedCount = $stmt->rowCount();
            
            
            $this->db->commit();
            return $insertedCount;
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logError("Insert failed: " . $e->getMessage());
            throw $e;
        }
    }

    private function updateInvalidServices($uploadId)
    {
        // Update invalid services to 'new-service'
        $query = "UPDATE `service_sheet_uploads` s
                  LEFT JOIN services sv ON s.service_name = sv.item
                  SET s.service_name = 'new-service'
                  WHERE sv.item IS NULL AND s.upload_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$uploadId]);
    }

    private function getTotalAmountFromTable($uploadId)
    {
        $query = "SELECT SUM(amount) FROM service_sheet WHERE reciept_no IN (
            SELECT reciept_no FROM service_sheet_uploads WHERE upload_id = ?
        )";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$uploadId]);
        return $stmt->fetchColumn();
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

    private function logError($message, $trace = null) {
        $logFile = __DIR__ . '/../../logs/debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $trace = $trace ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        
        $log = "[ERROR] {$timestamp}\n";
        $log .= "Message: {$message}\n";
        $log .= "Stack Trace:\n";
        
        foreach ($trace as $i => $frame) {
            $log .= sprintf(
                "#%d %s:%d - %s%s%s()\n",
                $i,
                $frame['file'] ?? 'unknown file',
                $frame['line'] ?? '0',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? ''
            );
        }
        $log .= "\n" . str_repeat('-', 80) . "\n";
        
        error_log($log, 3, $logFile);
    }

    private function deleteUploadRecords($uploadId)
    {
        try {
            $this->db->beginTransaction();
            $this->logDebug('Starting record deletion', ['upload_id' => $uploadId]);
            
            // Delete from uploads table
            $query = "DELETE FROM service_sheet_uploads WHERE upload_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$uploadId]);
            
            $this->db->commit();
            $this->logDebug("Deleted records for upload_id: " . $uploadId);
            $this->logDebug('Records deleted successfully', ['upload_id' => $uploadId]);
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logDebug("Error deleting records: " . $e->getMessage());
            $this->logDebug('Record deletion failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ], $e->getTrace());
            throw $e;
        }
    }

    private function checkDuplicateReceiptsBeforeInsert($sheetData)
    {
        $receiptNumbers = [];
        $duplicates = [];

        // First collect all receipt numbers from the sheet
        foreach ($sheetData as $rowIndex => $row) {
            if ($rowIndex === 1) continue; // Skip header
            $receiptNo = $row['B'];
            if (empty($receiptNo)) continue;

            // Check if receipt exists in database
            $stmt = $this->db->prepare("SELECT reciept_no FROM service_sheet WHERE reciept_no = ?");
            $stmt->execute([$receiptNo]);
            if ($stmt->fetchColumn()) {
                $duplicates[] = $receiptNo;
            }
        }

        return $duplicates;
    }

    // Add new method for actual data insertion
    public function processValidatedData($uploadId) {
        try {
            $this->logDebug('Starting data processing', ['upload_id' => $uploadId]);
            $tempDir = __DIR__ . '/../../src/temp/';
            $tempFile = $tempDir . $uploadId . '.json';

            if (!file_exists($tempFile)) {
                $this->logDebug('Temp file not found: ' . $tempFile);
                throw new Exception('Validation data not found. Please try uploading the file again.');
            }

            $tempData = json_decode(file_get_contents($tempFile), true);
            if (!$tempData || !isset($tempData['file_path'])) {
                $this->logDebug('Invalid temp data in file: ' . $tempFile);
                throw new Exception('Invalid validation data');
            }

            $filePath = $tempData['file_path'];
            if (!file_exists($filePath)) {
                $this->logDebug('Source file not found: ' . $filePath);
                throw new Exception('Source file not found');
            }

            try {
                // Load the spreadsheet again
                $spreadsheet = IOFactory::load($filePath);
                $sheet = $spreadsheet->getActiveSheet();
                $sheetData = $sheet->toArray(null, true, true, true);

                // Get total rows
                $highestRow = $sheet->getHighestRow();
                $totalRows = $highestRow - 1; // Exclude header row

                // Create the table if it doesn't exist
                $this->createTable();

                $batchData = []; // Initialize batch data array

                // Rest of the existing code remains the same
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

                    // Prepare data for insertion into the table
                    $rowData = [
                        'upload_id' => $uploadId,
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
                    if (count($batchData) >= $this->batchSize) {  // Change this line
                        $this->insertBatch($batchData);
                        $batchData = []; // Reset batch data
                    }
                }

                // Insert any remaining rows in the batch
                if (!empty($batchData)) {
                    $this->insertBatch($batchData);
                }

                // Check for duplicate receipt numbers
                $duplicateReceipts = $this->checkDuplicateReceipts($uploadId);
                if (!empty($duplicateReceipts)) {
                    $this->logDebug('Found duplicate receipts: ' . implode(', ', $duplicateReceipts));
                    
                    // Delete records from service_sheet_uploads
                    $this->deleteUploadRecords($uploadId);
                    
                    return [
                        'status' => 'error',
                        'message' => count($duplicateReceipts) . ' Duplicate Receipt Numbers Found: ' . 
                                    implode(', ', array_slice($duplicateReceipts, 0, 5)) . 
                                    (count($duplicateReceipts) > 5 ? ' and more...' : ''),
                        'duplicates' => $duplicateReceipts
                    ];
                }

                // If no duplicates, proceed with the rest of the process
                // Validate stylists and services using MySQL joins
                $invalidStylists = $this->checkInvalidStylists($uploadId);
                if (!empty($invalidStylists)) {
                    $this->deleteUploadRecords($uploadId);
                    return [
                        'status' => 'error',
                        'message' => 'Invalid stylists found: ' . implode(', ', $invalidStylists),
                        'invalidStylists' => $invalidStylists,
                        'invalidStylistsCount' => count($invalidStylists)
                    ];
                }

                // Update invalid services to 'new-service'
                $this->updateInvalidServices($uploadId);

                // Insert data from temporary table to final table
                $rowsInserted = $this->insertFromTable($uploadId);
                $totalAmount = $this->getTotalAmountFromTable($uploadId);
                
                $this->logDebug("Final counts for upload {$uploadId}:",__METHOD__,__LINE__);
                $this->logDebug("Rows inserted: {$rowsInserted}",__METHOD__,__LINE__);
                 $this->logDebug("Total amount: {$totalAmount}");
                $this->logDebug('Processing completed', [
                    'upload_id' => $uploadId,
                    'rows_inserted' => $rowsInserted,
                    'total_amount' => $totalAmount
                ]);

                // Clean up temporary file and return success
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }

                return [
                    'status' => 'success',
                    'rowsInserted' => $rowsInserted,
                    'totalAmount' => $totalAmount
                ];

            } catch (Exception $e) {
                // Clean up temporary file on error
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                throw $e;
            }
        } catch (Exception $e) {
            $this->logError('Process validation error: ' . $e->getMessage(), $e->getTrace());
            $this->logDebug('Processing failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage()
            ], $e->getTrace());
            throw $e;
        }
    }

}
