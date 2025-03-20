<?php

require_once '../vendor/autoload.php';
require_once __DIR__ . '/log_helper.php';

use App\Api\ExcelController;
use App\Config\Database;

class FileProcessor {
    private $jobId;
    private $uploadDir;
    private $processedDir;
    private $statusDir;
    private $statusFile;
    private $jobFilePath;
    private $controller;
    private $batchSize;

    public function __construct($jobId) {
        $this->jobId = $jobId;
        $this->uploadDir = __DIR__ . '/../uploads/';
        $this->processedDir = __DIR__ . '/../processed/';
        $this->statusDir = __DIR__ . '/../status/';
        $this->statusFile = $this->statusDir . 'status.json';
        $this->jobFilePath = $this->uploadDir . 'jobs.json';
        $this->batchSize = 500; // Adjust the batch size as needed

        // Create directories if they do not exist
        $this->createDirectory($this->uploadDir);
        $this->createDirectory($this->processedDir);
        $this->createDirectory($this->statusDir);

        // Load the configuration
        $config = require __DIR__ . '/../Config/config.php';

        // Pass the configuration array to the Database constructor
        $database = new \App\Config\Database($config['database']);

        // Reuse the token or adjust accordingly
        $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VybmFtZSI6ImV3YW5qYXUiLCJwYXNzd29yZCI6IjIzNCIsImlhdCI6MTY4OTQ2NTQ0OSwiZXhwIjoxNjg5NDY5MDQ5fQ.SJF7Ieq2Gc5hz5dWyb5vcOAsBdG04Z6eU2zGTtHOCa4';
        $this->controller = new ExcelController($database, $token);

        // Initialize job file if it doesn't exist
        if (!file_exists($this->jobFilePath)) {
            file_put_contents($this->jobFilePath, json_encode([]));
            log_message('FileProcessor: Initialized job file at ' . $this->jobFilePath);
        }
    }

    private function createDirectory($dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            log_message('FileProcessor: Created directory: ' . $dir);
        }
    }

    public function process() {
        if (!$this->jobId) {
            log_message('FileProcessor: No job ID provided.');
            exit('No job ID provided.');
        }

        log_message('FileProcessor: Started processing script for job ID: ' . $this->jobId);

        try {
            // Get the job details
            $jobs = json_decode(file_get_contents($this->jobFilePath), true);
            $currentJob = null;
            foreach ($jobs as $job) {
                if ($job['id'] === $this->jobId) {
                    $currentJob = $job;
                    break;
                }
            }

            if (!$currentJob || !isset($currentJob['upload_id'])) {
                throw new Exception("Upload ID not found in job data");
            }

            // Ensure the temp directory exists
            $tempDir = __DIR__ . '/../../src/temp/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            // Call the database insertion method with the upload_id from the job
            $result = $this->controller->processValidatedData($currentJob['upload_id']);

            if ($result['status'] === 'success') {
                log_message("Processing succeeded with {$result['rowsInserted']} rows inserted");
                // Update status on success, ensuring values are copied
                $status = [
                    'status' => 'Completed',
                    'message' => 'File processing completed successfully.',
                    'rowsInserted' => $result['rowsInserted'] ?? 0,
                    'totalAmount' => $result['totalAmount'] ?? 0,
                    'progress' => 100
                ];
                $this->updateJobStatus('processed');

                // Move file to processed directory
                if (isset($currentJob['file']) && file_exists($this->uploadDir . $currentJob['file'])) {
                    $sourceFile = $this->uploadDir . $currentJob['file'];
                    $destFile = $this->processedDir . $currentJob['file'];
                    
                    if (rename($sourceFile, $destFile)) {
                        log_message('FileProcessor: Moved file to processed directory: ' . $destFile);
                    } else {
                        log_message('FileProcessor: Failed to move file to processed directory: ' . $destFile);
                    }
                }

                // Log the final counts
                log_message('FileProcessor: Completed processing with ' . 
                    $result['rowsInserted'] . ' rows inserted, total amount: ' . 
                    $result['totalAmount']);
            } else {
                log_message("Processing failed with error: " . $result['message']);
                // Update status on error with more detail
                $status = [
                    'status' => 'Error',
                    'message' => $result['message'],
                    'progress' => 0,
                    'rowsInserted' => 0,
                    'totalAmount' => 0,
                    'error_details' => isset($result['duplicates']) ? [
                        'type' => 'duplicate_receipts',
                        'count' => count($result['duplicates']),
                        'samples' => array_slice($result['duplicates'], 0, 5)
                    ] : null
                ];
                $this->updateJobStatus('error');
                
                log_message('FileProcessor: Processing failed: ' . $result['message']);
                if (isset($result['duplicates'])) {
                    log_message('FileProcessor: Found ' . count($result['duplicates']) . ' duplicate receipts');
                }
            }

        } catch (Exception $e) {
            $errorMsg = sprintf(
                "Error processing file:\nMessage: %s\nFile: %s\nLine: %d\nTrace:\n%s",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
            log_message($errorMsg);
            $status = [
                'status' => 'Error',
                'message' => $e->getMessage(),
                'progress' => 0
            ];
            $this->updateJobStatus('error');
        }

        file_put_contents($this->statusFile, json_encode($status));
        log_message('FileProcessor: Finished with status: ' . $status['status']);
    }

    private function updateJobStatus($status) {
        $jobData = json_decode(file_get_contents($this->jobFilePath), true);
        foreach ($jobData as &$job) {
            if ($job['id'] === $this->jobId) {
                $job['status'] = $status;
                $job['processed_at'] = date('c'); // ISO 8601 date format
                break;
            }
        }
        file_put_contents($this->jobFilePath, json_encode($jobData, JSON_PRETTY_PRINT));
        log_message('FileProcessor: Job status updated for job ID ' . $this->jobId . ' to ' . $status);
    }
}

// Function to process file
function process_file($jobId) {
    $processor = new FileProcessor($jobId);
    $processor->process();
}
?>
