<?php
namespace App\Config;

use PDO;

class Database
{
    // Database connection parameters
    private $host = 'localhost';
    private $db_name = 'nyweless';
    private $username = 'root';
    private $password = 'gentleman';
    public $conn;

    // Method to establish a database connection
    public function getConnection()
    {
        $this->conn = null;

        try {
            // Create a new PDO instance and set the connection parameters
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            // Set the character encoding to UTF-8
            $this->conn->exec("set names utf8");
        } catch (\PDOException $exception) { // Use \PDOException
            // Handle any connection errors
            echo "Connection error: " . $exception->getMessage();
        }

        // Return the established connection
        return $this->conn;
    }
}
?>
