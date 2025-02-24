<?php

namespace App\Api;

use PDOException;
use Exception;

class ValidationController
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function validateData()
    {
        $response = ['status' => 'error', 'message' => 'Unknown error'];

        try {
            // SQL query to validate data in uploadData table
            $stmt = $this->db->prepare("SELECT reciept_no, entry_date, details FROM uploadData WHERE is_valid = 0");
            $stmt->execute();
            $invalidRows = $stmt->fetchAll();

            if (empty($invalidRows)) {
                $response = ['status' => 'success', 'message' => 'All data is valid.'];
            } else {
                $response = ['status' => 'error', 'invalidRows' => $invalidRows];
            }
        } catch (PDOException $e) {
            $response['message'] = 'Database error occurred: ' . $e->getMessage();
            $this->logError($e->getMessage());
        } catch (Exception $e) {
            $response['message'] = 'An error occurred while validating the data: ' . $e->getMessage();
            $this->logError($e->getMessage());
        }

        return $response;
    }

    private function logError($message)
    {
        error_log($message, 3, __DIR__ . '/../../logs/debug.log');
    }
}
