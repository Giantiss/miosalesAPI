<?php
use PHPUnit\Framework\TestCase;
use App\Config\Database;

class DatabaseTest extends TestCase
{
    public function testConnection()
    {
        $database = new Database();
        $connection = $database->getConnection();
        $this->assertNotNull($connection, "Database connection should not be null");
    }
}
?>
