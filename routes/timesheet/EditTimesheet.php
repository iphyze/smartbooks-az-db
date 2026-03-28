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
        throw new Exception("Unauthorized: Only Admins or Controllers can update timesheets", 401);
    }

    /**
     * Decode JSON body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Validation for required fields (Scalar update based on ID)
     */
    $requiredFields = [
        'id', 'date', 'staff_name', 'staff_id', 
        'clients_name', 'clients_id', 'task', 'start_time', 'finish_time'
    ];

    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    /**
     * Clean inputs
     */
    $id = (int) $data['id'];
    $date = trim($data['date']);
    $staff_name = trim($data['staff_name']);
    $staff_id = trim($data['staff_id']);
    $clients_name = trim($data['clients_name']);
    $clients_id = trim($data['clients_id']);
    $task = trim($data['task']);
    $start_time = trim($data['start_time']);
    $finish_time = trim($data['finish_time']);
    
    // Optional fields
    $project = isset($data['project']) ? trim($data['project']) : '';

    // Start Transaction
    $conn->begin_transaction();

    try {

        /**
         * 1. Check Accounting Period Lock
         */
        $periodStmt = $conn->prepare("SELECT * FROM accounting_periods ORDER BY id DESC LIMIT 1");
        $periodStmt->execute();
        $periodResult = $periodStmt->get_result();
        $periodData = $periodResult->fetch_assoc();
        $periodStmt->close();

        if ($periodData) {
            $start_date = $periodData['start_date'];
            $end_date = $periodData['end_date'];
            $is_locked = $periodData['is_locked'];

            if ($end_date >= $date && $is_locked == "Locked") {
                throw new Exception("This accounting period is locked!", 400);
            }
        }

        /**
         * 2. Check Timesheet Existence
         */
        $checkStmt = $conn->prepare("SELECT id FROM timesheet_table WHERE id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $res = $checkStmt->get_result();
        if ($res->num_rows === 0) {
            throw new Exception("Timesheet entry ID {$id} not found.", 404);
        }
        $checkStmt->close();

        /**
         * 3. Validate Foreign Keys
         */
        // Check Staff Existence
        $staffStmt = $conn->prepare("SELECT * FROM staff_table WHERE staff_name = ?");
        $staffStmt->bind_param("s", $staff_name);
        $staffStmt->execute();
        $staffRes = $staffStmt->get_result();
        if ($staffRes->num_rows == 0) {
            throw new Exception("$staff_name does not exist in the database!", 404);
        }
        $staffStmt->close();

        // Check Client Existence
        $clientStmt = $conn->prepare("SELECT * FROM clients_table WHERE clients_name = ?");
        $clientStmt->bind_param("s", $clients_name);
        $clientStmt->execute();
        $clientRes = $clientStmt->get_result();
        if ($clientRes->num_rows == 0) {
            throw new Exception("$clients_name does not exist in the database!", 404);
        }
        $clientStmt->close();

        /**
         * 4. Calculate Total Hours
         */
        $total_hours = 0;
        $start_ts = strtotime($start_time);
        $finish_ts = strtotime($finish_time);

        if ($start_ts && $finish_ts) {
            $diff = $finish_ts - $start_ts;
            $total_hours = $diff / 3600; // Convert seconds to hours
        } else {
            throw new Exception("Invalid time format provided.", 400);
        }

        /**
         * 5. Update Timesheet Entry
         */
        $stmtUpdate = $conn->prepare("
            UPDATE timesheet_table SET 
                staff_name = ?,
                staff_id = ?,
                clients_name = ?,
                clients_id = ?,
                date = ?,
                project = ?,
                task = ?,
                start_time = ?,
                finish_time = ?,
                total_hours = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        if (!$stmtUpdate) {
            throw new Exception("Database prepare failed: " . $conn->error, 500);
        }

        $stmtUpdate->bind_param(
            "sssssssssdsi", 
            $staff_name,
            $staff_id,
            $clients_name,
            $clients_id,
            $date,
            $project,
            $task,
            $start_time,
            $finish_time,
            $total_hours,
            $userEmail,
            $id
        );

        if (!$stmtUpdate->execute()) {
            throw new Exception("Error updating timesheet entry: " . $stmtUpdate->error, 500);
        }
        
        $stmtUpdate->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail updated Timesheet Entry #$id for $staff_name";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        // Commit Transaction
        $conn->commit();

        echo json_encode([
            "status" => "Success",
            "message" => "Timesheet entry updated successfully!",
            "data" => [
                "id" => $id,
                "total_hours" => $total_hours
            ]
        ]);

    } catch (Exception $e) {
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