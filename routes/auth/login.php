<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
use Firebase\JWT\JWT;
use Respect\Validation\Validator as v;
use Dotenv\Dotenv;


header('Content-Type: application/json');

try {
    // Load environment variables
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->load();

    // Ensure the request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    // Get the JSON input
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->email) || !isset($data->password)) {
        throw new Exception("Email and Password", 400);
    }

    $email = trim($data->email);
    $password = trim($data->password);

    // Define validators
    $emailValidator = v::email()->notEmpty();
    $passwordValidator = v::stringType()->length(6, null);

    // Validate email
    if (!$emailValidator->validate($email)) {
        throw new Exception("Invalid email format", 400);
    }

    // Validate password
    if (!$passwordValidator->validate($password)) {
        throw new Exception("Password must be at least 6 characters long", 400);
    }

    // Secure email input
    $email = mysqli_real_escape_string($conn, $email);

    // Query database
    $sql = "SELECT * FROM admin_table WHERE email = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $conn->error, 500);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Database execution error: " . $stmt->error, 500);
    }

    if ($result->num_rows === 0) {
        throw new Exception("Invalid email or password", 401);
    }

    $user = $result->fetch_assoc();

    // Verify password
    if (!password_verify($password, $user['password'])) {
        throw new Exception("Invalid email or password", 401);
    }

    // Generate JWT
    $secretKey = $_ENV["JWT_SECRET"] ?: "smartbooks_secret_key";
    $tokenPayload = [
        "id" => $user['id'],
        "email" => $user['email'],
        "integrity" => $user['integrity'],
        "exp" => time() + ($_ENV["JWT_EXPIRES_IN"] ?: 5 * 24 * 60 * 60) // 5 days expiration
    ];
    $token = JWT::encode($tokenPayload, $secretKey, 'HS256');


    if($user){

        $get = "INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($get);
        if (!$stmt) {
            throw new Exception("Database query preparation failed: " . $conn->error, 500);
        }

        $action = $user['fname'] . " " . $user['lname'] . " logged in successfully";
        $created_by = $user['fname'] . " " . $user['lname'];

        $stmt->bind_param("iss", $user['id'], $action, $created_by);
        $stmt->execute();


        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "Login successful",
            "data" => [
                "id" => $user['id'],
                "fname" => $user['fname'],
                "lname" => $user['lname'],
                "username" => $user['username'],
                "email" => $user['email'],
                "integrity" => $user['integrity'],
                "token" => $token,
                "created_by" => $user['created_by'],
                "updated_by" => $user['updated_by'],
            ]
        ]);

    }else{
        throw new Exception("Bad Request", 400);
    }

} catch (Exception $e) {
    // Handle errors properly
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}


?>