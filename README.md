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
- **Duplicate Detection:** Identifies and skips duplicate rows during the upload process.

## Technologies
- **Backend Language:** PHP 7.2
- **Database:** MySQL
- **Authentication:** JWT (JSON Web Token)
- **File Handling:** PHP's native file handling with `$_FILES`
- **Excel Parsing:** PHPExcel or PhpSpreadsheet for reading and parsing Excel files
- **Logging:** Monolog for logging activities and errors
- **Rate Limiting:** Custom middleware for rate limiting
- **Data Sanitization:** PHP's filter functions and prepared statements

## Solutions to Common Issues

### Identifying Duplicates
Duplicates are identified by checking unique fields in each row against existing records in the database. If a duplicate is found, the row is skipped, and an error message is logged.

### Data Sanitization
All input data is sanitized using PHP's filter functions and prepared statements to prevent SQL injection and other security vulnerabilities.

### Error Reporting
Detailed error messages are provided for each invalid row. These messages include the specific validation error and the row number, making it easy to identify and correct issues.

### File Upload Logging
All file upload activities and errors are logged using Monolog. This includes the timestamp of the upload, the user who uploaded the file, and any errors encountered during processing.

## API Endpoints

### Upload File
- **URL:** `/index.php/upload`
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
    "success": true,
    "message": "File uploaded successfully. Ready for processing.",
    "jobId": "unique_job_id"
  }
  ```
- **Error:**
  ```json
  {
    "error": "Detailed error message"
  }
  ```

### Check Duplicate
- **URL:** `/index.php/check-duplicate`
- **Method:** `POST`
- **Content-Type:** `application/json`
- **Headers:**
  - `Authorization: Bearer <token>`
- **Body:**
  ```json
  {
    "hash": "file_hash"
  }
  ```

#### Response
- **Success:**
  ```json
  {
    "isDuplicate": false
  }
  ```
- **Error:**
  ```json
  {
    "error": "Detailed error message"
  }
  ```

### Start Processing
- **URL:** `/index.php/start-processing`
- **Method:** `POST`
- **Content-Type:** `application/json`
- **Headers:**
  - `Authorization: Bearer <token>`
- **Body:**
  ```json
  {
    "jobId": "unique_job_id"
  }
  ```

#### Response
- **Success:**
  ```json
  {
    "success": true,
    "message": "Processing started.",
    "jobId": "unique_job_id",
    "output": "Processing output"
  }
  ```
- **Error:**
  ```json
  {
    "error": "Detailed error message"
  }
  ```

### Check Status
- **URL:** `/index.php/check-status`
- **Method:** `GET`
- **Headers:**
  - `Authorization: Bearer <token>`

#### Response
- **Success:**
  ```json
  {
    "status": "Processing status details"
  }
  ```
- **Error:**
  ```json
  {
    "error": "Detailed error message"
  }
  ```

