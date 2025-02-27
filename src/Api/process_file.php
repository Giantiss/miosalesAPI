<?php

require_once '../vendor/autoload.php';
require_once __DIR__ . '/log_helper.php';

use App\Api\ExcelController;
use App\Config\Database;

// Define the process_file function
function process_file($jobId) {
    if (!$jobId) {
        log_message('process_file.php: No job ID provided.');
        exit('No job ID provided.');
    }

    log_message('process_file.php: Started processing script for job ID: ' . $jobId);

    // Define the upload directory and processed directory
    $uploadDir = __DIR__ . '/../uploads/';
    $processedDir = __DIR__ . '/../processed/';
    $statusDir = __DIR__ . '/../status/';

    // Create directories if they do not exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
        log_message('process_file.php: Created upload directory.');
    }

    if (!is_dir($processedDir)) {
        mkdir($processedDir, 0777, true);
        log_message('process_file.php: Created processed directory.');
    }

    if (!is_dir($statusDir)) {
        mkdir($statusDir, 0777, true);
        log_message('process_file.php: Created status directory.');
    }

    // Define the status file
    $statusFile = $statusDir . 'status.json';

    // Initialize status
    $status = ['status' => 'Processing', 'message' => '', 'file' => ''];

    // Write initial status
    file_put_contents($statusFile, json_encode($status));
    log_message('process_file.php: Initial status written to ' . $statusFile);

    // Get all Excel files in the upload directory
    $allowedExtensions = ['xlsx', 'xls'];
    $files = array_filter(scandir($uploadDir), function ($file) use ($uploadDir, $allowedExtensions) {
        $filePath = $uploadDir . $file;
        $fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return is_file($filePath) && in_array($fileExtension, $allowedExtensions);
    });

    if (empty($files)) {
        log_message('process_file.php: No new files to process.');
        $status['status'] = 'No files';
        $status['message'] = 'No new files to process.';
        file_put_contents($statusFile, json_encode($status));
        log_message('process_file.php: Status updated to "No files"');
        exit("No new files to process.");
    }

    log_message('process_file.php: Found files to process: ' . implode(', ', $files));

    // Create a database connection
    $database = new Database();
    $db = $database->getConnection();

    // Reuse the token or adjust accordingly
    $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VybmFtZSI6ImV3YW5qYXUiLCJwYXNzd29yZCI6IjIzNCIsImlhdCI6MTY4OTQ2NTQ0OSwiZXhwIjoxNjg5NDY5MDQ5fQ.SJF7Ieq2Gc5hz5dWyb5vcOAsBdG04Z6eU2zGTtHOCa4';
    $controller = new ExcelController($db, $token);

    // Define the batch size
    $batchSize = 500; // Adjust the batch size as needed

    // Function to update job file status
    function updateJobStatus($jobFilePath, $jobId, $status)
    {
        $jobData = json_decode(file_get_contents($jobFilePath), true);
        foreach ($jobData as &$job) {
            if ($job['id'] === $jobId) {
                $job['status'] = $status;
                $job['processed_at'] = date('c'); // ISO 8601 date format
                break;
            }
        }
        file_put_contents($jobFilePath, json_encode($jobData, JSON_PRETTY_PRINT));
        log_message('process_file.php: Job status updated for job ID ' . $jobId . ' to ' . $status);
    }

    // Define the job file path
    $jobFilePath = __DIR__ . '/../uploads/jobs.json';

    // Initialize job file if it doesn't exist
    if (!file_exists($jobFilePath)) {
        file_put_contents($jobFilePath, json_encode([]));
        log_message('process_file.php: Initialized job file at ' . $jobFilePath);
    }

    // Process each file
    foreach ($files as $file) {
        $filePath = $uploadDir . $file;
        log_message('process_file.php: Processing file: ' . $filePath);

        try {
            // Call the importExcel method to process the file in batches
            $result = $controller->importExcel($filePath, $batchSize);

            // Log the result of the processing
            log_message('process_file.php: File processing result: ' . print_r($result, true));

            // Move the processed file to the processed directory
            $newLocation = $processedDir . $file;
            if (rename($filePath, $newLocation)) {
                log_message('process_file.php: File moved to: ' . $newLocation);
            } else {
                log_message('process_file.php: Failed to move file: ' . $filePath);
            }

            // Update job file status on success
            updateJobStatus($jobFilePath, $jobId, 'processed');

            // Update status on success
            $status['status'] = 'Completed';
            $status['message'] = 'File processing completed successfully.';
            $status['file'] = $file;
            file_put_contents($statusFile, json_encode($status));
            log_message('process_file.php: Status updated to "Completed"');
        } catch (Exception $e) {
            log_message('process_file.php: Error processing file: ' . $filePath . ' - ' . $e->getMessage());

            // Update job file status on error
            updateJobStatus($jobFilePath, $jobId, 'error');

            // Update status on error
            $status['status'] = 'Error';
            $status['message'] = 'Error processing file: ' . $e->getMessage();
            $status['file'] = $file;
            file_put_contents($statusFile, json_encode($status));
            log_message('process_file.php: Status updated to "Error"');
        }
    }

    log_message('process_file.php: Finished processing.');
}

// Check if the script is being run from the command line
if (php_sapi_name() === 'cli') {
    $jobId = $argv[1] ?? null;
    process_file($jobId);
}
?>
