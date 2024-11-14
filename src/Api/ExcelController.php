<?php

namespace App\Api;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PDOException;
use DateTime;
use Exception;

ini_set('display_errors', 1);
error_reporting(E_ALL);

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
        $fileName = basename($filePath); // Get the file name
        $totalRows = 0;
        $progressId = 0;

        try {
            // Check if file exists
            if (!file_exists($filePath)) {
                $response['message'] = 'File not found.';
                return $response;
            }

            // Load the spreadsheet
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $sheetData = $sheet->toArray(null, true, true, true);

            $highestRow = $sheet->getHighestRow(); // Get the actual last row with data
            $totalRows = $highestRow - 1; // Exclude header row

            // Insert initial progress record
            $stmt = $this->db->prepare("INSERT INTO file_upload_progress (file_name, total_rows, rows_processed, progress_percentage) VALUES (?, ?, 0, 0)");
            $stmt->execute([$fileName, $totalRows]);

            $rowsInserted = 0;

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

                // Insert the row into the service_sheet table
                try {
                    $this->insertRow($rowData);
                } catch (Exception $e) {
                    $response['message'] = "Error inserting row $rowIndex: " . $e->getMessage();
                    return $response;
                }

                $rowsInserted++;
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


    // Methods to find the corresponding IDs for customer, stylist, and service

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
        $stmt = $this->db->prepare("SELECT id FROM services WHERE name = ?");
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

    private function insertRow($rowData)
    {
        try {
            // Ensure $rowData is valid
            if (empty($rowData)) {
                throw new Exception("Invalid or empty data provided for insert.");
            }

            // Log the data being inserted to verify it
            error_log("Inserting Row: " . print_r($rowData, true), 3, __DIR__ . '/../../logs/debug.log');

            // Start a transaction
            $this->db->beginTransaction();

            // Prepare the query
            $query = "INSERT INTO service_sheet (entry_date, reciept_no, stylist, service, amount, net, spa_transaction, expunged, v_account) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            // Prepare the parameters
            $params = [
                $rowData['entry_date'],
                $rowData['reciept_no'],
                $rowData['stylist'],
                $rowData['service'],
                $rowData['amount'],
                $rowData['net'],
                $rowData['spa_transaction'], // 'N' by default
                $rowData['expunged'],        // 'N' by default
                $rowData['v_account']       // 'N' by default
            ];

            // Log the query and parameters to verify they are being passed correctly
            error_log("Executing Query: $query with Params: " . print_r($params, true), 3, __DIR__ . '/../../logs/debug.log');

            // Execute the insert query row by row
            $stmt = $this->db->prepare($query);
            $result = $stmt->execute($params);

            // Check if the query was successful
            if ($stmt->rowCount() > 0) {
                error_log("Row inserted successfully.", 3, __DIR__ . '/../../logs/debug.log');
            } else {
                error_log("No rows inserted for data: " . print_r($rowData, true), 3, __DIR__ . '/../../logs/debug.log');
            }

            // Check if the query was successful
            if (!$result) {
                // If the insertion fails, get the error details
                $errorInfo = $this->db->errorInfo();
                error_log("Database: " . print_r($errorInfo, true), 3, __DIR__ . '/../../logs/debug.log');
            } else {
                // Handle successful query execution
                echo "Data inserted successfully!";
            }
            
            // Commit the transaction
            $this->db->commit();
            
        } catch (PDOException $e) {
            // Log the error and roll back the transaction
            $this->db->rollBack();
            $this->db->errorInfo(); // This will return an array with SQL error details.
            $this->logError("Database insert failed: " . $e->getMessage());
            throw new Exception("Database insert failed: " . $e->getMessage());
        } catch (Exception $e) {
            // Catch any other exceptions and roll back
            $this->db->rollBack();
            $this->logError("Error: " . $e->getMessage());
            throw new Exception("Error: " . $e->getMessage());
        }
    }

    private function logError($message)
    {
        error_log($message, 3, __DIR__ . '/../../logs/debug.log');
    }
}
