# Excel File Upload API

## Overview
This API allows users to upload an Excel file, which is then processed row by row. Each row is validated, and valid rows are inserted into a MySQL database. The API supports Bearer token authentication and returns detailed validation errors for any invalid rows.

## Features
- **File Upload:** Accepts Excel files (`.xlsx`, `.xls`,`.csv`).
- **Row Validation:** Validates each row according to predefined criteria.
- **Database Insertion:** Inserts valid rows into the MySQL database.
- **Error Reporting:** Provides detailed error messages for invalid rows.
- **Bearer Token Authentication:** Secures the API with a Bearer token.

## Technologies
- **Backend Language:** PHP 7.2
- **Database:** MySQL
- **Authentication:** JWT (JSON Web Token)
- **File Handling:** PHP's native file handling with `$_FILES`
- **Excel Parsing:** PHPExcel or PhpSpreadsheet for reading and parsing Excel files

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
