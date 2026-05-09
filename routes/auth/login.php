<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';

use Firebase\JWT\JWT;
use Respect\Validation\Validator as v;
use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->load();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->email) || !isset($data->password)) {
        throw new Exception("Email and password are required", 400);
    }

    $email    = trim($data->email);
    $password = trim($data->password);

    // Validate inputs
    if (!v::email()->notEmpty()->validate($email)) {
        throw new Exception("Invalid email format", 400);
    }
    if (!v::stringType()->length(6, null)->validate($password)) {
        throw new Exception("Password must be at least 6 characters long", 400);
    }

    // Use prepared statements — no need for mysqli_real_escape_string
    $stmt = $conn->prepare("SELECT * FROM admin_table WHERE email = ?");
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Same message for wrong email OR wrong password — prevents user enumeration
        throw new Exception("Invalid email or password", 401);
    }

    $user = $result->fetch_assoc();

    if (!password_verify($password, $user['password'])) {
        throw new Exception("Invalid email or password", 401);
    }

    // Build JWT
    $secretKey  = $_ENV["JWT_SECRET"] ?? "smartbooks_secret_key";
    $expiresIn  = (int)($_ENV["JWT_EXPIRES_IN"] ?? (5 * 24 * 60 * 60)); // cast to int

    $tokenPayload = [
        "id"        => $user['id'],
        "email"     => $user['email'],
        "integrity" => $user['integrity'],
        "iat"       => time(),                  // issued-at (good practice)
        "exp"       => time() + $expiresIn,
    ];

    $token = JWT::encode($tokenPayload, $secretKey, 'HS256');

    // Log the login action
    $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
    if ($logStmt) {
        $action     = $user['fname'] . " " . $user['lname'] . " logged in successfully";
        $createdBy  = $user['fname'] . " " . $user['lname'];
        $logStmt->bind_param("iss", $user['id'], $action, $createdBy);
        $logStmt->execute();
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Login successful",
        "data"    => [
            "id"          => $user['id'],
            "fname"       => $user['fname'],
            "lname"       => $user['lname'],
            "username"    => $user['username'],
            "email"       => $user['email'],
            "integrity"   => $user['integrity'],
            "token"       => $token,
            "created_by"  => $user['created_by'],
            "updated_by"  => $user['updated_by'],
        ]
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
