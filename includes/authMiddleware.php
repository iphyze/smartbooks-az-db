<?php
// require_once __DIR__ . '/../vendor/autoload.php';

require_once './vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;


// Load environment variables
$dotenv = Dotenv::createImmutable('./');
$dotenv->load();


function authenticateUser() {
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode([
            "status" => "Failed",
            "message" => "Unauthorized",
            ]);
        exit;
    }

    $token = str_replace('Bearer ', '', $headers['Authorization']);
    $secretKey = $_ENV["JWT_SECRET"] ?: "default_secret_key";

    try {
        $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
        return (array) $decoded;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode([
            "status" => "Failed",
            "message" => "Invalid or expired token"
            ]);
        exit;
    }
}
?>
