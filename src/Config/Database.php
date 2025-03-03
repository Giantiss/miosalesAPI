<?php
namespace App\Config;

use PDO;

class Database
{
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct($config)
    {
        $this->host = $config['host'];
        $this->db_name = $config['dbname'];
        $this->username = $config['username'];
        $this->password = $config['password'];
    }

    // Method to establish a database connection
    public function getConnection()
    {
        $this->conn = null;

        try {
            // Create a new PDO instance and set the connection parameters
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
