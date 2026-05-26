<?php
// login.php
session_start();
require_once 'includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT id, password_hash, full_name, role, status FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['status'] === 'Inactive') {
                $error = "Account is inactive. Please contact the administrator.";
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                
                // Log the login
                $log_stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, module, ip_address) VALUES (?, 'Logged In', 'Authentication', ?)");
                $log_stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);

                header("Location: index.php");
                exit();
            }
        } else {
            $error = "Invalid credentials. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Login | AgriERP System</title>
    <style>
        :root {
            --primary-color: #005A9E; /* Microsoft Dynamics Blue */
            --bg-color: #f3f4f7;
            --text-color: #333333;
            --border-color: #e1dfdd;
            --error-color: #d13438;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            background-color: var(--bg-color);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            color: var(--text-color);
        }
        .login-container {
            background: #ffffff;
            width: 100%;
            max-width: 400px;
            padding: 40px;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05), 0 0 1px rgba(0, 0, 0, 0.1);
        }
        .login-container h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--primary-color);
        }
        .login-container p {
            font-size: 14px;
            color: #666;
            margin-bottom: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 2px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        .btn-login {
            width: 100%;
            background-color: var(--primary-color);
            color: #fff;
            border: none;
            padding: 12px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 2px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-login:hover {
            background-color: #004578;
        }
        .error-message {
            background-color: #fde7e9;
            color: var(--error-color);
            padding: 10px;
            border-radius: 2px;
            font-size: 13px;
            margin-bottom: 20px;
            border-left: 3px solid var(--error-color);
        }
    </style>
</head>
<body>

<div class="login-container">
    <h1>AgriERP System</h1>
    <p>Sign in to access your dashboard</p>

    <?php if ($error): ?>
        <div class="error-message">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="form-group">
            <label for="username">User ID</label>
            <input type="text" id="username" name="username" required autocomplete="username" autofocus>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn-login">Sign In</button>
    </form>
</div>

</body>
</html>