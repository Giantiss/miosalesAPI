<?php
use PHPUnit\Framework\TestCase;

class ExcelApiTest extends TestCase
{
    private $baseUrl = 'http://localhost:8000/MiosalonAPI';

    private function sendRequest($method, $endpoint, $token = null, $file = null)
    {
        $headers = [];
        if ($token) {
            $headers[] = 'Authorization: ' . $token;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($method == 'POST' && $file) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($file)]);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode;
    }

    public function testUploadSuccess()
    {
        $httpCode = $this->sendRequest('POST', '/upload', 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VybmFtZSI6ImV3YW5qYXUiLCJwYXNzd29yZCI6IjIzNCIsImlhdCI6MTY4OTQ2NTQ0OSwiZXhwIjoxNjg5NDY5MDQ5fQ.SJF7Ieq2Gc5hz5dWyb5vcOAsBdG04Z6eU2zGTtHOCa4', __DIR__ . '/orders.xls');
        $this->assertEquals(200, $httpCode);
    }

    // public function testUploadInvalidFile()
    // {
    //     $httpCode = $this->sendRequest('POST', '/upload', 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VybmFtZSI6ImV3YW5qYXUiLCJwYXNzd29yZCI6IjIzNCIsImlhdCI6MTY4OTQ2NTQ0OSwiZXhwIjoxNjg5NDY5MDQ5fQ.SJF7Ieq2Gc5hz5dWyb5vcOAsBdG04Z6eU2zGTtHOCa4', __DIR__ . '/invalid.txt');
    //     $this->assertEquals(400, $httpCode);
    // }

    public function testUploadNoFile()
    {
        $httpCode = $this->sendRequest('POST', '/upload', 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VybmFtZSI6ImV3YW5qYXUiLCJwYXNzd29yZCI6IjIzNCIsImlhdCI6MTY4OTQ2NTQ0OSwiZXhwIjoxNjg5NDY5MDQ5fQ.SJF7Ieq2Gc5hz5dWyb5vcOAsBdG04Z6eU2zGTtHOCa4');
        $this->assertEquals(400, $httpCode);
    }

    public function testUploadInvalidToken()
    {
        $httpCode = $this->sendRequest('POST', '/upload', 'Bearer invalid_token', __DIR__ . '/orders.xls');
        $this->assertEquals(401, $httpCode);
    }
}
?>
