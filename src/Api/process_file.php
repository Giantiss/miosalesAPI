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

        // Initialize status
        $status = ['status' => 'Processing', 'message' => '', 'file' => ''];

        // Write initial status
        file_put_contents($this->statusFile, json_encode($status));
        log_message('FileProcessor: Initial status written to ' . $this->statusFile);

        // Get all Excel files in the upload directory
        $allowedExtensions = ['xlsx', 'xls'];
        $files = array_filter(scandir($this->uploadDir), function ($file) use ($allowedExtensions) {
            $filePath = $this->uploadDir . $file;
            $fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return is_file($filePath) && in_array($fileExtension, $allowedExtensions);
        });

        if (empty($files)) {
            log_message('FileProcessor: No new files to process.');
            $status['status'] = 'No files';
            $status['message'] = 'No new files to process.';
            file_put_contents($this->statusFile, json_encode($status));
            log_message('FileProcessor: Status updated to "No files"');
            exit("No new files to process.");
        }

        log_message('FileProcessor: Found files to process: ' . implode(', ', $files));

        // Process each file
        foreach ($files as $file) {
            $filePath = $this->uploadDir . $file;
            log_message('FileProcessor: Processing file: ' . $filePath);

            try {
                // Call the importExcel method to process the file in batches
                $result = $this->controller->importExcel($filePath, $this->batchSize);

                // Log the result of the processing
                log_message('FileProcessor: File processing result: ' . print_r($result, true));

                // Move the processed file to the processed directory
                $newLocation = $this->processedDir . $file;
                if (rename($filePath, $newLocation)) {
                    log_message('FileProcessor: File moved to: ' . $newLocation);
                } else {
                    log_message('FileProcessor: Failed to move file: ' . $filePath);
                }

                // Update job file status on success
                $this->updateJobStatus('processed');

                // Update status on success
                $status['status'] = 'Completed';
                $status['message'] = 'File processing completed successfully.';
                $status['file'] = $file;
                file_put_contents($this->statusFile, json_encode($status));
                log_message('FileProcessor: Status updated to "Completed"');
            } catch (Exception $e) {
                log_message('FileProcessor: Error processing file: ' . $filePath . ' - ' . $e->getMessage());

                // Update job file status on error
                $this->updateJobStatus('error');

                // Update status on error
                $status['status'] = 'Error';
                $status['message'] = 'Error processing file: ' . $e->getMessage();
                $status['file'] = $file;
                file_put_contents($this->statusFile, json_encode($status));
                log_message('FileProcessor: Status updated to "Error"');
            }
        }

        log_message('FileProcessor: Finished processing.');
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
