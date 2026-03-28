<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $userEmail = $userData['email'];
    $userIntegrity = $userData['integrity'];

    if (!in_array($userIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized: Only Admins or Controllers can update currency rates", 401);
    }

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid input format. Expected JSON object.", 400);
    }

    /**
     * Required fields
     */
    $requiredFields = ['id', 'ngn_cur', 'ngn_rate', 'usd_cur', 'usd_rate', 'gbp_cur', 'gbp_rate', 'eur_cur', 'eur_rate', 'created_at'];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $id = (int) $data['id'];

    if ($id <= 0) {
        throw new Exception("Invalid Currency ID provided.", 400);
    }

    /**
     * Clean and Validate inputs
     */
    $ngn_cur = trim($data['ngn_cur']);
    $usd_cur = trim($data['usd_cur']);
    $gbp_cur = trim($data['gbp_cur']);
    $eur_cur = trim($data['eur_cur']);
    $created_at = trim($data['created_at']);

    // Validate rates are numeric
    if (!is_numeric($data['ngn_rate']) || !is_numeric($data['usd_rate']) || !is_numeric($data['gbp_rate']) || !is_numeric($data['eur_rate'])) {
        throw new Exception("All currency rates must be numeric values.", 400);
    }

    $ngn_rate = (float) $data['ngn_rate'];
    $usd_rate = (float) $data['usd_rate'];
    $gbp_rate = (float) $data['gbp_rate'];
    $eur_rate = (float) $data['eur_rate'];

    /**
     * Check if record exists
     */
    $checkStmt = $conn->prepare("
        SELECT id 
        FROM currency_table 
        WHERE id = ?
    ");

    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows === 0) {
        throw new Exception("Currency record with ID {$id} not found.", 404);
    }

    $checkStmt->close();

    /**
     * Update record
     */
    $updateStmt = $conn->prepare("
        UPDATE currency_table
        SET 
            ngn_cur = ?, ngn_rate = ?, 
            usd_cur = ?, usd_rate = ?, 
            gbp_cur = ?, gbp_rate = ?, 
            eur_cur = ?, eur_rate = ?, 
            created_at = ?, 
            updated_by = ?, updated_at = NOW()
        WHERE id = ?
    ");

    // Bind types: 
    // s (string), d (double), s (string), d (double), s (string), d (double), s (string), d (double), s (string), i (integer)
    $updateStmt->bind_param(
        "sdsdsdsdssi",
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
        $id
    );

    if (!$updateStmt->execute()) {
        throw new Exception("Update failed: " . $updateStmt->error, 500);
    }

    $updateStmt->close();

    /**
     * Log action
     */
    $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");

    $action = "$userEmail updated currency rates [ID {$id}]";

    $logStmt->bind_param("iss", $loggedInUserId, $action, $userEmail);
    $logStmt->execute();
    $logStmt->close();

    /**
     * Fetch updated record
     */
    $fetchStmt = $conn->prepare("
        SELECT 
            id,
            ngn_cur,
            ngn_rate,
            usd_cur,
            usd_rate,
            gbp_cur,
            gbp_rate,
            eur_cur,
            eur_rate,
            created_at,
            created_by,
            updated_at,
            updated_by
        FROM currency_table
        WHERE id = ?"
    );

    $fetchStmt->bind_param("i", $id);
    $fetchStmt->execute();

    $updatedData = $fetchStmt->get_result()->fetch_assoc();

    $fetchStmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Currency rates updated successfully",
        "data" => $updatedData
    ]);

} catch (Exception $e) {

    error_log("Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}