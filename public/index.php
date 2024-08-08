<?php
require_once '../vendor/autoload.php';

use App\Api\ExcelController;
use App\Config\Database;

// Create a database connection
$database = new Database();
$db = $database->getConnection();

// Create an instance of the controller
$controller = new ExcelController($db, 'your_token_here');

// Define a simple logger function for debugging
function log_message($message) {
    file_put_contents('../logs/debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

// Get the request method and URI
$requestMethod = $_SERVER['REQUEST_METHOD'];
$pathInfo = isset($_SERVER['REQUEST_URI']) ? explode('/', trim($_SERVER['REQUEST_URI'], '/')) : [];

// Check for the Authorization header
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : null;
log_message('Received Authorization header: ' . $authHeader);

// Validate Bearer token
$validToken = "Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VybmFtZSI6ImV3YW5qYXUiLCJwYXNzd29yZCI6IjIzNCIsImlhdCI6MTY4OTQ2NTQ0OSwiZXhwIjoxNjg5NDY5MDQ5fQ.SJF7Ieq2Gc5hz5dWyb5vcOAsBdG04Z6eU2zGTtHOCa4";

if ($authHeader !== $validToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    log_message('Unauthorized access attempt');
    exit();
}

// Route the request
if ($requestMethod == 'POST' && isset($pathInfo[1]) && $pathInfo[1] == 'upload') {
    // Handle file upload
    if (isset($_FILES['file'])) {
        $fileTmpName = $_FILES['file']['tmp_name'];
        log_message('File upload path: ' . $fileTmpName);
        
        if (!is_uploaded_file($fileTmpName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file upload']);
            log_message('Invalid file upload');
            exit();
        }

        $response = $controller->importExcel($fileTmpName);
        
        if ($response['status'] === 'success') {
            http_response_code(200);
            echo json_encode(['message' => 'File imported successfully']);
            log_message('File imported successfully');
        } else {
            http_response_code(400);
            echo json_encode(['error' => $response['message']]);
            log_message('File import failed: ' . $response['message']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        log_message('No file uploaded');
    }
} elseif ($requestMethod == 'GET' && isset($pathInfo[1]) && $pathInfo[1] == 'download') {
    // Handle file download
    $filePath = __DIR__ . '/downloads/miosales_export.xlsx';

    $response = $controller->exportExcel($filePath);

    if ($response['status'] === 'success') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="miosales_export.xlsx"');
        readfile($filePath);
        log_message('File exported successfully and downloaded');
        exit();
    } else {
        http_response_code(400);
        echo json_encode(['error' => $response['message']]);
        log_message('File export failed: ' . $response['message']);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    log_message('Invalid request method or path');
}
