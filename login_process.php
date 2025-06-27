<?php
session_start();
require_once 'db_connect.php';

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Validate input
    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Please enter both username and password";
        header("Location: login.php");
        exit();
    }

    // Prepare SQL statement to get user
    $stmt = $conn->prepare("
        SELECT u.*, r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.username = ? AND u.password = ?
    ");
    
    if (!$stmt) {
        $_SESSION['error'] = "Database error: " . $conn->error;
        header("Location: login.php");
        exit();
    }

    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    // Check if user exists with matching credentials
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        // Check if account is active
        if (!$user['is_active']) {
            $_SESSION['error'] = "Your account is inactive. Please contact support.";
            header("Location: login.php");
            exit();
        }

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['user_role'] = $user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];

        // Update last login time
        $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->bind_param("i", $user['id']);
        $updateStmt->execute();

        // Log activity
        $activityStmt = $conn->prepare("
            INSERT INTO user_activities (user_id, activity_type, description, ip_address, user_agent)
            VALUES (?, 'login', 'User logged in', ?, ?)
        ");
        $activityStmt->bind_param("iss", $user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        $activityStmt->execute();

        // Redirect based on role
        if ($user['role_id'] == 1) { // Admin
            header("Location: admin_dashboard.php");
        } else { // Regular user
            header("Location: user_dashboard.php");
        }
        exit();
    } else {
        $_SESSION['error'] = "Invalid username or password";
        header("Location: login.php");
        exit();
    }
} else {
    // Not a POST request
    header("Location: login.php");
    exit();
}
?>