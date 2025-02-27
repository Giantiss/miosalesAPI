# Excel File Upload API

## Overview
This API allows users to upload an Excel file, which is then processed row by row. Each row is validated, and valid rows are inserted into a MySQL database. The API supports Bearer token authentication and returns detailed validation errors for any invalid rows.

## Features
- **File Upload:** Accepts Excel files (`.xlsx`, `.xls`,`.csv`).
- **Row Validation:** Validates each row according to predefined criteria.
- **Database Insertion:** Inserts valid rows into the MySQL database.
- **Error Reporting:** Provides detailed error messages for invalid rows.
- **Bearer Token Authentication:** Secures the API with a Bearer token.
- **Logging:** Logs all upload activities and errors for auditing purposes.
- **Rate Limiting:** Limits the number of file uploads per user to prevent abuse.
- **File Size Limit:** Enforces a maximum file size for uploads to ensure performance.
- **Data Sanitization:** Sanitizes input data to prevent SQL injection and other security issues.

## Technologies
- **Backend Language:** PHP 7.2
- **Database:** MySQL
- **Authentication:** JWT (JSON Web Token)
- **File Handling:** PHP's native file handling with `$_FILES`
- **Excel Parsing:** PHPExcel or PhpSpreadsheet for reading and parsing Excel files
- **Logging:** Monolog for logging activities and errors
- **Rate Limiting:** Custom middleware for rate limiting
- **Data Sanitization:** PHP's filter functions and prepared statements

## API Endpoints

### Upload File
- **URL:** `/index.php`
- **Method:** `POST`
- **Content-Type:** `multipart/form-data`
- **Headers:**
  - `Authorization: Bearer <token>`
- **Form Data:**
  - `file`: The Excel file to be uploaded

#### Response
- **Success:**
  ```json
  {
    "message": "File processed successfully"
  }
  ```
- **Error:**
  ```json
  {
    "error": "Detailed error message"
  }
  ```

### Get Upload Logs
- **URL:** `/logs.php`
- **Method:** `GET`
- **Headers:**
  - `Authorization: Bearer <token>`

#### Response
- **Success:**
  ```json
  {
    "logs": [
      {
        "timestamp": "2023-10-01T12:00:00Z",
        "message": "File uploaded successfully"
      },
      // ...more logs...
    ]
  }
  ```
- **Error:**
  ```json
  {
    "error": "Detailed error message"
  }
  ```

### Rate Limit Status
- **URL:** `/rate_limit.php`
- **Method:** `GET`
- **Headers:**
  - `Authorization: Bearer <token>`

#### Response
- **Success:**
  ```json
  {
    "rate_limit": {
      "limit": 10,
      "remaining": 5,
      "reset": "2023-10-01T12:00:00Z"
    }
  }
  ```
- **Error:**
  ```json
  {
    "error": "Detailed error message"
  }
  ```
