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
        throw new Exception("Unauthorized: Only Admins or Controllers can create timesheets", 401);
    }

    /**
     * Decode JSON body
     */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    /**
     * Validate Date (Global for the batch)
     */
    if (!isset($data['date']) || empty(trim($data['date']))) {
        throw new Exception("Please ensure that the work date is selected!", 400);
    }

    $date = trim($data['date']);

    /**
     * Validate Array Fields (Timesheet Entries)
     */
    $requiredArrays = ['staff_name', 'staff_id', 'clients_name', 'clients_id', 'task', 'start_time', 'finish_time'];
    
    // Check if project is part of the payload, if not, we handle it later
    $hasProject = isset($data['project']) && is_array($data['project']);

    foreach ($requiredArrays as $field) {
        if (!isset($data[$field]) || !is_array($data[$field]) || empty($data[$field])) {
            throw new Exception("Please ensure that you have at least added a timesheet entry with valid {$field}!", 400);
        }
    }

    // Check array counts match
    $count = count($data['staff_name']);
    foreach ($requiredArrays as $field) {
        if (count($data[$field]) !== $count) {
            throw new Exception("Mismatch in timesheet entry data count for {$field}.", 400);
        }
    }
    if ($hasProject && count($data['project']) !== $count) {
        throw new Exception("Mismatch in timesheet entry data count for project.", 400);
    }

    // Start Transaction
    $conn->begin_transaction();

    try {

        /**
         * 1. Check Accounting Period
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

            // Logic from legacy: if end_date >= current_date AND locked
            if ($end_date >= $date && $is_locked == "Locked") {
                throw new Exception("This accounting period is locked!", 400);
            }
        }

        /**
         * 2. Prepare Insert Statement
         */
        $stmtInsert = $conn->prepare("
            INSERT INTO timesheet_table 
            (staff_name, staff_id, date, clients_name, clients_id, project, task, start_time, finish_time, total_hours, created_by, updated_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmtInsert) {
            throw new Exception("Database prepare failed: " . $conn->error, 500);
        }

        /**
         * 3. Loop and Process Entries
         */
        for ($i = 0; $i < $count; $i++) {
            
            // Extract and clean data
            $staff_name = trim($data['staff_name'][$i]);
            $staff_id = trim($data['staff_id'][$i]);
            $clients_name = trim($data['clients_name'][$i]);
            $clients_id = trim($data['clients_id'][$i]);
            $task = trim($data['task'][$i]);
            $start_time = trim($data['start_time'][$i]);
            $finish_time = trim($data['finish_time'][$i]);
            
            // Optional project field
            $project = ($hasProject && isset($data['project'][$i])) ? trim($data['project'][$i]) : '';

            // Validation for empty fields within the loop
            if (empty($staff_name)) {
                throw new Exception("Please ensure that all staff names are filled!", 400);
            }
            if (empty($clients_name)) {
                throw new Exception("Please ensure that all client names are filled!", 400);
            }
            if (empty($start_time)) {
                throw new Exception("Please ensure that all start times are filled!", 400);
            }
            if (empty($finish_time)) {
                throw new Exception("Please ensure that all finish times are filled!", 400);
            }

            /**
             * Validate Foreign Keys
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
             * Calculate Total Hours
             */
            $total_hours = 0;
            $start_ts = strtotime($start_time);
            $finish_ts = strtotime($finish_time);

            if ($start_ts && $finish_ts) {
                $diff = $finish_ts - $start_ts;
                $total_hours = $diff / 3600; // Convert seconds to hours
            } else {
                throw new Exception("Invalid time format for entry #" . ($i + 1), 400);
            }

            // Bind and Execute Insert
            $stmtInsert->bind_param(
                "sssssssssdss", 
                $staff_name,
                $staff_id,
                $date,
                $clients_name,
                $clients_id,
                $project,
                $task,
                $start_time,
                $finish_time,
                $total_hours,
                $userEmail,
                $userEmail
            );

            if (!$stmtInsert->execute()) {
                throw new Exception("Error inserting timesheet entry: " . $stmtInsert->error, 500);
            }
        }

        $stmtInsert->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("INSERT INTO logs (userId, action, created_by) VALUES (?, ?, ?)");
        $logAction = "$userEmail created Timesheet entries for date $date";
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $userEmail);
        $logStmt->execute();
        $logStmt->close();

        // Commit Transaction
        $conn->commit();

        http_response_code(200);

        echo json_encode([
            "status" => "Success",
            "message" => "Timesheet entries created successfully!",
            "data" => [
                "date" => $date,
                "entries_count" => $count
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