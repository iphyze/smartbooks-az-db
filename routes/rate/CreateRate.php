<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can create currency rates", 401);
    }

    /**
     * Decode JSON body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Required fields validation
     */
    $requiredFields = [
        'ngn_cur', 'ngn_rate', 
        'usd_cur', 'usd_rate', 
        'gbp_cur', 'gbp_rate', 
        'eur_cur', 'eur_rate',
        'created_at'
    ];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Clean inputs
     */
    $ngn_cur = trim($data['ngn_cur']);
    $usd_cur = trim($data['usd_cur']);
    $gbp_cur = trim($data['gbp_cur']);
    $eur_cur = trim($data['eur_cur']);
    $created_at = trim($data['created_at']);
    $updated_at = trim($data['created_at']);

    // Validate rates are numeric
    if (!is_numeric($data['ngn_rate']) || !is_numeric($data['usd_rate']) || !is_numeric($data['gbp_rate']) || !is_numeric($data['eur_rate'])) {
        throw new Exception("All currency rates must be numeric values.", 400);
    }

    // Ensure rates are float/decimal
    $ngn_rate = (float) $data['ngn_rate'];
    $usd_rate = (float) $data['usd_rate'];
    $gbp_rate = (float) $data['gbp_rate'];
    $eur_rate = (float) $data['eur_rate'];

    // Start Transaction
    $conn->begin_transaction();

    try {

        /**
         * Insert Currency Data
         */
        $insertStmt = $conn->prepare("
            INSERT INTO currency_table 
            (ngn_cur, ngn_rate, usd_cur, usd_rate, gbp_cur, gbp_rate, eur_cur, eur_rate, created_at, created_by, updated_at, updated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Bind types: 
        // s = string (for _cur columns)
        // d = double/float (for _rate columns)
        // s = string (for emails/usernames)
        $insertStmt->bind_param(
            "sdsdsdsdssss", 
            $ngn_cur,
            $ngn_rate,
            $usd_cur,
            $usd_rate,
            $gbp_cur,
            $gbp_rate,
            $eur_cur,
            $eur_rate,
            $created_at,
            $userEmail,
            $updated_at,
            $userEmail
        );

        if (!$insertStmt->execute()) {
            throw new Exception("Database insert failed: " . $insertStmt->error, 500);
        }

        $insertedId = $insertStmt->insert_id;
        $insertStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        
        $logAction = "$userEmail created new currency rates [ID: {$insertedId}]";

        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        // Commit Transaction
        $conn->commit();

        http_response_code(201); // 201 Created

        echo json_encode([
            "status" => "Success",
            "message" => "Currency rates created successfully",
            "data" => [
                "id" => $insertedId,
                "ngn_cur" => $ngn_cur,
                "ngn_rate" => $ngn_rate,
                "usd_cur" => $usd_cur,
                "usd_rate" => $usd_rate,
                "gbp_cur" => $gbp_cur,
                "gbp_rate" => $gbp_rate,
                "eur_cur" => $eur_cur,
                "eur_rate" => $eur_rate,
                "created_at" => $created_at,
                "created_by" => $userEmail
            ]
        ]);

    } catch (Exception $e) {
        // Rollback Transaction on error
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}