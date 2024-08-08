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
$pathInfo = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));

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

log_message('Request Method: ' . $requestMethod);
log_message('Path Info: ' . print_r($pathInfo, true));

// Expecting the path to be ['MiosalonAPI', 'upload'] or ['MiosalonAPI', 'download']
if ($requestMethod == 'POST' && isset($pathInfo[1]) && $pathInfo[1] == 'upload') {
    // Handle file upload
    if (isset($_FILES['file'])) {
        $fileTmpName = $_FILES['file']['tmp_name'];
        log_message('File upload path: ' . $fileTmpName);

        if (!is_uploaded_file($fileTmpName)) {
            http_response_code(400);
            $responseData = json_encode(['error' => 'Invalid file upload']);
            header('Content-Length: ' . strlen($responseData));
            echo $responseData;
            log_message('Invalid file upload');
            exit();
        }

        $response = $controller->importExcel($fileTmpName);
        
        if ($response['status'] === 'success') {
            http_response_code(200);
            $responseData = json_encode(['message' => 'File imported successfully']);
            header('Content-Length: ' . strlen($responseData));
            echo $responseData;
            log_message('File imported successfully');
        } else {
            http_response_code(400);
            $responseData = json_encode(['error' => $response['message']]);
            header('Content-Length: ' . strlen($responseData));
            echo $responseData;
            log_message('File import failed: ' . $response['message']);
        }
    } else {
        http_response_code(400);
        $responseData = json_encode(['error' => 'No file uploaded']);
        header('Content-Length: ' . strlen($responseData));
        echo $responseData;
        log_message('No file uploaded');
    }
} elseif ($requestMethod == 'GET' && isset($pathInfo[1]) && $pathInfo[1] == 'download') {
    // Handle file download
    $filePath = __DIR__ . '/downloads/miosales_export.xlsx';

    $response = $controller->exportExcel($filePath);

    if ($response['status'] === 'success') {
        $fileSize = filesize($filePath);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="miosales_export.xlsx"');
        header('Content-Length: ' . $fileSize);

        readfile($filePath);
        log_message('File exported successfully and downloaded');
        exit();
    } else {
        http_response_code(400);
        $responseData = json_encode(['error' => $response['message']]);
        header('Content-Length: ' . strlen($responseData));
        echo $responseData;
        log_message('File export failed: ' . $response['message']);
    }
} else {
    http_response_code(400);
    $responseData = json_encode(['error' => 'Invalid request']);
    header('Content-Length: ' . strlen($responseData));
    echo $responseData;
    log_message('Invalid request method or path');
}
