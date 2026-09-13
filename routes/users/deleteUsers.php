<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authorization.php';
require_once 'utils/rbac_helpers.php';

header('Content-Type: application/json');
date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    

    requirePermission($conn, $userData, 'user.delete', 'You do not have permission to delete users.');

    // Decode request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['userIds']) || !is_array($data['userIds']) || count($data['userIds']) === 0) {
        throw new Exception("Please select at least one user to delete.", 400);
    }

    $userIds = array_map('intval', $data['userIds']);

    // Prevent self-deletion
    if (in_array($loggedInUserId, $userIds)) {
        throw new Exception("You cannot delete your own account.", 400);
    }

    // Super Admin accounts are protected from bulk deletion.
    $placeholdersCheck = implode(',', array_fill(0, count($userIds), '?'));
    $protectSql = "SELECT a.id, a.email, a.integrity, r.code AS rbac_role_code, r.is_super_admin
                   FROM admin_table a
                   LEFT JOIN rbac_user_roles ur ON ur.user_id = a.id
                   LEFT JOIN rbac_roles r ON r.id = ur.role_id
                   WHERE a.id IN ($placeholdersCheck)";
    $protectStmt = $conn->prepare($protectSql);
    if (!$protectStmt) {
        throw new Exception('Unable to validate selected user accounts.', 500);
    }
    $protectStmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);
    $protectStmt->execute();
    $selectedUsers = $protectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $protectStmt->close();
    foreach ($selectedUsers as $selectedUser) {
        $selectedUser['id'] = (int) ($selectedUser['id'] ?? 0);
        if (rbacIsSuperAdmin($selectedUser)) {
            throw new Exception('Super Admin accounts cannot be deleted.', 400);
        }
        assertActorCanManageRbacTarget($conn, $userData, $selectedUser);
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        /**
         * Delete users
         */
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $deleteQuery = "DELETE FROM admin_table WHERE id IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteQuery);

        if (!$deleteStmt) {
            throw new Exception("Database error: Failed to prepare delete statement", 500);
        }

        $deleteStmt->bind_param(str_repeat('i', count($userIds)), ...$userIds);

        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete users: " . $deleteStmt->error, 500);
        }

        if ($deleteStmt->affected_rows === 0) {
            throw new Exception("No matching users found to delete.", 404);
        }

        $deleteStmt->close();

        /**
         * Log action
         */
        $logStmt = $conn->prepare("
            INSERT INTO logs (userId, action, created_by)
            VALUES (?, ?, ?)
        ");

        $logAction = "{$loggedInUserEmail} deleted user account(s) with ID(s): " . implode(', ', $userIds);
        $logStmt->bind_param("iss", $loggedInUserId, $logAction, $loggedInUserEmail);

        if (!$logStmt->execute()) {
            throw new Exception("Failed to log delete action: " . $logStmt->error, 500);
        }

        $logStmt->close();

        // Commit transaction
        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "User account(s) deleted successfully."
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
        "message" => publicErrorMessage($e)
    ]);
}
