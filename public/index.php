<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

require_once '../vendor/autoload.php';

use App\Api\ExcelController;
use App\Config\Database;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Create a database connection
$database = new Database();
$db = $database->getConnection();

// Create an instance of the controller
$controller = new ExcelController($db, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VybmFtZSI6ImV3YW5qYXUiLCJwYXNzd29yZCI6IjIzNCIsImlhdCI6MTY4OTQ2NTQ0OSwiZXhwIjoxNjg5NDY5MDQ5fQ.SJF7Ieq2Gc5hz5dWyb5vcOAsBdG04Z6eU2zGTtHOCa4');

// Logger function for debugging
function log_message($message)
{
    file_put_contents('../logs/debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

// Get the request method and URI
$requestMethod = $_SERVER['REQUEST_METHOD'];
$pathInfo = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));

// Check for the Authorization header
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : null;
log_message('Received Authorization header: ' . ($authHeader ?? 'None'));

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

// Expecting the path to be ['MiosalonAPI', 'upload'] or ['upload']
if ($requestMethod === 'POST' && isset($pathInfo[0]) && 
    ($pathInfo[0] === 'MiosalonAPI' && isset($pathInfo[1]) && $pathInfo[1] === 'upload' || 
     $pathInfo[0] === 'upload')) {
    if (isset($_FILES['file'])) {
        $fileTmpName = $_FILES['file']['tmp_name'];
        $fileName = basename($_FILES['file']['name']);

        // Check file extension
        $allowedExtensions = ['xlsx', 'xls'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        log_message('File extension: ' . $fileExtension);

        // Validate file extension
        if (!in_array($fileExtension, $allowedExtensions)) {
            http_response_code(400);
            $responseData = json_encode(['error' => $fileExtension . ' Invalid file extension. Only .xlsx and .xls files are allowed.']);
            echo $responseData;
            log_message('Invalid file extension: ' . $fileExtension);
            exit();
        }

        // Validate MIME type
        $allowedMimeTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
            'application/vnd.ms-excel' // xls
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($fileTmpName);
        log_message('Detected MIME type: ' . $mimeType);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            http_response_code(400);
            $responseData = json_encode(['error' => 'Invalid file type. Only Excel files are allowed.']);
            echo $responseData;
            log_message('Invalid MIME type: ' . $mimeType);
            exit();
        }

        // Attempt to open the file with PhpSpreadsheet
        try {
            $spreadsheet = IOFactory::load($fileTmpName);

            // If the file was successfully loaded, proceed with your import logic
            $response = $controller->importExcel($fileTmpName);

            if ($response['status'] === 'success') {
                http_response_code(200);
                $responseData = json_encode(['message' => 'File imported successfully']);
                echo $responseData;
                log_message('File imported successfully');
            } else {
                http_response_code(400);
                $responseData = json_encode(['error' => $response['message']]);
                echo $responseData;
                log_message('File import failed: ' . $response['message']);
            }
        } catch (Exception $e) {
            http_response_code(400);
            $responseData = json_encode(['error' => 'Warning: Invalid file type. Only Excel files are allowed!']);
            echo $responseData;
            log_message('Invalid file type or corrupted file: ' . $e->getMessage());
            exit();
        }
    } else {
        http_response_code(400);
        $responseData = json_encode(['error' => 'No file uploaded']);
        echo $responseData;
        log_message('No file uploaded');
    }
} else {
    http_response_code(400);
    $responseData = json_encode(['error' => 'Invalid request']);
    echo $responseData;
    log_message('Invalid request method or path');
}
