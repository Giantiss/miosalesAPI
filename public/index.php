<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/Api/log_helper.php';
require_once __DIR__ . '/../src/Config/config.php';

use App\Api\ExcelController;
use App\Config\Database;

$config = require __DIR__ . '/../src/Config/config.php';

// Create a database connection
$database = new Database($config['database']);
$db = $database->getConnection();

// Create an instance of the controller
$controller = new ExcelController($database, $config['bearer_token']);

// Include the process_file.php script
require_once __DIR__ . '/../src/Api/process_file.php';

// Get the request method and URI
$requestMethod = $_SERVER['REQUEST_METHOD'];
$pathInfo = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));

// Check for the Authorization header
$authHeader = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : null;
if (!$authHeader && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : null;
}

log_message('Received Authorization header: ' . ($authHeader ?? 'None'));

// Validate Bearer token
$validToken = $config['bearer_token'];

// Ensure $authHeader is a string
$authHeader = (string)$authHeader;

if (!hash_equals($validToken, $authHeader)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    log_message('Unauthorized access attempt');
    exit();
}

log_message('Request Method: ' . $requestMethod);
log_message('Path Info: ' . print_r($pathInfo, true));

// Directory for uploaded files
$uploadDir = __DIR__ . '/../src/uploads/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create upload directory.']);
        log_message('Failed to create upload directory: ' . $uploadDir);
        exit();
    }
}

// Route Handling
if ($requestMethod === 'POST' && isset($pathInfo[0])) {
    switch ($pathInfo[0]) {
        case 'upload':
            if (isset($_FILES['file'])) {
                $file = $_FILES['file'];
                $fileTmpName = $file['tmp_name'];
                $fileName = basename($file['name']);

                // Validate file extension
                $allowedExtensions = ['xlsx', 'xls'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                log_message('File extension: ' . $fileExtension);

                if (!in_array($fileExtension, $allowedExtensions)) {
                    http_response_code(400);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => $fileExtension . ' Invalid file extension. Only .xlsx and .xls files are allowed.']);
                    log_message('Invalid file extension: ' . $fileExtension);
                    exit();
                }

                // Check if the user allowed duplicate
                $allowDuplicate = isset($_POST['allowDuplicate']) && $_POST['allowDuplicate'] === 'true';

                // Calculate file hash for duplicate check
                $hash = md5_file($fileTmpName);
                $isDuplicate = false;

                // Check for duplicates only if not allowed
                if (!$allowDuplicate) {
                    foreach (scandir($uploadDir) as $existingFile) {
                        if ($existingFile !== '.' && $existingFile !== '..') {
                            $existingFilePath = $uploadDir . $existingFile;
                            if (md5_file($existingFilePath) === $hash) {
                                $isDuplicate = true;
                                break;
                            }
                        }
                    }
                }

                if ($isDuplicate) {
                    http_response_code(409);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Duplicate file detected.', 'isDuplicate' => true]);
                    log_message('Duplicate file detected: ' . $fileName);
                    exit();
                }

                // Save the uploaded file temporarily
                $savedFileName = time() . '_' . $fileName;
                $tempDestination = $uploadDir . 'temp_' . $savedFileName;

                if (move_uploaded_file($fileTmpName, $tempDestination)) {
                    // Process the file to check for duplicates
                    $result = $controller->importExcel($tempDestination);

                    if ($result['status'] === 'error') {
                        // Delete the temporary file
                        unlink($tempDestination);
                        http_response_code(400);
                        header('Content-Type: application/json');
                        echo json_encode(['error' => $result['message']]);
                        log_message('File processing error: ' . $result['message']);
                        exit();
                    }

                    // Move the processed file to the uploads directory
                    $finalDestination = $uploadDir . $savedFileName;
                    rename($tempDestination, $finalDestination);

                    // Add the job to the queue
                    $jobFilePath = $uploadDir . 'jobs.json';
                    $jobs = file_exists($jobFilePath) ? json_decode(file_get_contents($jobFilePath), true) : [];
                    $jobId = uniqid();
                    $jobs[] = ['id' => $jobId, 'file' => $savedFileName, 'status' => 'uploaded', 'uploaded_at' => date('c')];
                    file_put_contents($jobFilePath, json_encode($jobs, JSON_PRETTY_PRINT));
                    log_message('Job added to the queue: ' . $jobFilePath);

                    // Return a response immediately to the client.
                    http_response_code(200);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'File uploaded successfully. Ready for processing.',
                        'jobId' => $jobId
                    ]);
                } else {
                    http_response_code(500);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Failed to move uploaded file']);
                    log_message('Failed to move uploaded file');
                }
            }
            break;

        case 'check-duplicate':
            $requestData = json_decode(file_get_contents('php://input'), true);
            $hash = $requestData['hash'] ?? '';

            if (empty($hash)) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'No hash provided.']);
                exit();
            }

            // Check if any file in the uploads or processed directory matches the hash
            $isDuplicate = false;
            $directories = [$uploadDir, __DIR__ . '/../src/processed/'];
            foreach ($directories as $directory) {
                if (!is_dir($directory)) {
                    continue;
                }
                foreach (scandir($directory) as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $filePath = $directory . $file;
                        if (md5_file($filePath) === $hash) {
                            $isDuplicate = true;
                            break 2; // Exit both loops
                        }
                    }
                }
            }

            header('Content-Type: application/json');
            echo json_encode(['isDuplicate' => $isDuplicate]);
            break;

        case 'start-processing':
            $requestData = json_decode(file_get_contents('php://input'), true);
            $jobId = $requestData['jobId'] ?? null;

            if (!$jobId) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'No job ID provided.']);
                exit();
            }

            // Create an instance of FileProcessor and call the process method
            $processor = new FileProcessor($jobId);
            ob_start();
            $processor->process();
            $output = ob_get_clean();
            log_message('Processing started for job ID: ' . $jobId);

            // Return a response immediately to the client.
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Processing started.',
                'jobId' => $jobId,
                'output' => $output
            ]);
            break;

        case 'check-status':
            $statusFilePath = __DIR__ . '/../src/status/status.json';
            log_message('Checking status file: ' . $statusFilePath);
            if (file_exists($statusFilePath)) {
                $status = json_decode(file_get_contents($statusFilePath), true);
                log_message('Status file content: ' . print_r($status, true));

                // Ensure rowsInserted and totalAmount are included in the response
                $rowsInserted = $status['rowsInserted'] ?? 0;
                $totalAmount = $status['totalAmount'] ?? 0;

                header('Content-Type: application/json');
                echo json_encode([
                    'status' => $status['status'],
                    'rowsInserted' => $rowsInserted,
                    'totalAmount' => $totalAmount,
                    'message' => $status['message'] ?? ''
                ]);
            } else {
                log_message('Status file not found: ' . $statusFilePath);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'No status available']);
            }
            break;

        default:
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid route']);
            break;
    }
} else {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid request']);
    log_message('Invalid request method or path');
}
?>
