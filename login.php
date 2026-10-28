<?php
session_start();
include 'config.php';

/*
 * Admin SSO:
 * If the admin is already logged in through the main Patient Module,
 * open Module BDI/BAI without asking for the password again.
 *
 * Normal Module BDI/BAI users (role 2) continue to use the existing
 * login form exactly as before.
 */
if (
    isset($_SESSION['user_id']) &&
    isset($_SESSION['role']) &&
    (int)$_SESSION['role'] === 1
) {
    $_SESSION['user_login'] = '1';

    header("Location: dashboard.php");
    exit();
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!empty($email) && !empty($password)) {
        $hashed_password = sha1($password);

        $stmt = $conn->prepare("SELECT id, first_name, last_name, password, sessions,role_id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if ($hashed_password === $user['password'] || password_verify($password, $user['password'])) {
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['role'] = $user['role_id'];
                $_SESSION['current_patient_id'] = $user['id'];
                $_SESSION['user_login'] = '1';
                
                $session_token = bin2hex(random_bytes(16));
                $_SESSION['user_token'] = $session_token;

                $sessions_array = json_decode($user['sessions'], true);
                if (!is_array($sessions_array)) {
                    $sessions_array = [];
                }
                $sessions_array[] = $session_token;
                $updated_sessions_json = json_encode($sessions_array);

                $update_stmt = $conn->prepare("UPDATE users SET sessions = ? WHERE id = ?");
                $update_stmt->bind_param("si", $updated_sessions_json, $user['id']);
                $update_stmt->execute();
                $update_stmt->close();

                if ($_SESSION['role'] == 1) {
                    header("Location: dashboard.php");
                } elseif ($_SESSION['role'] == 2) {
                    header("Location: public-assessment-form.php");
                } else {
                    header("Location: login.php");
                }

                exit();
            } else {
                $error = "Invalid password. Please try again.";
            }
        } else {
            $error = "No account found with this email address.";
        }
        $stmt->close();
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in - VIMHANS Academics</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-blue: #2563EB;
            --btn-blue: #4F46E5;
            --text-dark: #1F2937;
            --text-gray: #6B7280;
            --text-light-gray: #9CA3AF;
            --border-color: #E5E7EB;
            --bg-color: #FAFAFA;
        }

        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
            font-family: 'Inter', sans-serif; 
        }

        body {
            background-color: var(--bg-color);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--text-dark);
        }

        .container {
            display: flex;
            max-width: 1100px;
            width: 100%;
            padding: 20px 40px;
            align-items: center;
            justify-content: space-between;
            gap: 60px;
        }

        /* --- Left Section Styles --- */
        .info-section {
            flex: 1;
            max-width: 500px;
        }

        .info-section h1 {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1.2;
            color: #111827;
            margin-bottom: 1rem;
        }

        .info-section h1 .brand-text {
            color: var(--brand-blue);
        }

        .info-section p.subtitle {
            color: var(--text-gray);
            font-size: 1rem;
            line-height: 1.5;
            margin-bottom: 3rem;
            max-width: 90%;
        }

        .features-grid {
            display: flex;
            gap: 2rem;
        }

        .feature-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.75rem;
        }

        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
        }

        .icon-green { background-color: #D1FAE5; }
        .icon-pink { background-color: #FCE7F3; }
        .icon-purple { background-color: #F3E8FF; }

        .feature-item span {
            font-size: 0.85rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        /* --- Right Section (Login Card) Styles --- */
        .login-section {
            flex: 1;
            max-width: 440px;
            width: 100%;
        }

        .login-card {
            background: #ffffff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.08);
            width: 100%;
        }

        .login-card h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #4B5563; 
        }

        .alert-box {
            background-color: #FEF2F2;
            color: #DC2626;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #FECACA;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: #4B5563;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
        }

        input {
            width: 100%;
            padding: 12px 20px;
            border: 1px solid var(--border-color);
            border-radius: 50px; 
            font-size: 0.9rem;
            color: var(--text-dark);
            background-color: #ffffff; /* Ensures background is white */
            outline: none;
            transition: all 0.2s ease-in-out;
        }
        
        input::placeholder {
            color: var(--text-light-gray);
        }

        /* Focus state matching your reference image with a thicker border */
        input:focus {
            border-color: var(--brand-blue);
            border-width: 2px;
            padding: 11px 19px; /* Adjust padding slightly to prevent layout shift when border gets thicker */
        }

        .eye-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            font-size: 1.1rem;
            user-select: none;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .eye-icon:hover {
            opacity: 1;
        }

        .forgot-password {
            display: block;
            text-align: right;
            font-size: 0.8rem;
            color: var(--text-gray);
            text-decoration: none;
            margin-top: -5px;
            margin-bottom: 20px;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .disclaimer {
            font-size: 0.75rem;
            color: var(--text-light-gray);
            margin-bottom: 1.25rem;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--btn-blue);
            border: none;
            color: #fff;
            border-radius: 50px; 
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background 0.2s;
        }

        .btn-login:hover {
            background: #4338ca;
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.5rem 0;
            color: var(--text-light-gray);
            font-size: 0.85rem;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid var(--border-color);
        }

        .divider::before { margin-right: .5em; }
        .divider::after { margin-left: .5em; }

        .footer-action {
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-gray);
        }

        .footer-action a {
            color: var(--brand-blue);
            text-decoration: none;
            font-weight: 500;
        }

        .footer-action a:hover {
            text-decoration: underline;
        }

        /* Responsive Design */
        @media (max-width: 900px) {
            .container {
                flex-direction: column;
                justify-content: center;
                gap: 40px;
            }
            .info-section {
                text-align: center;
                max-width: 100%;
            }
            .info-section p.subtitle {
                margin: 0 auto 2rem auto;
            }
            .features-grid {
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Left Information Section -->
    <div class="info-section">
        <h1>Log in to <span class="brand-text">VIMHANS</span><br>Academics account</h1>
        <p class="subtitle">A learning platform by vimhans, a trusted mental health institution since 1981.</p>
        
        <div class="features-grid">
            <div class="feature-item">
                <div class="feature-icon icon-green"></div>
                <span>Expert faculty</span>
            </div>
            <div class="feature-item">
                <div class="feature-icon icon-pink"></div>
                <span>Secure login</span>
            </div>
            <div class="feature-item">
                <div class="feature-icon icon-purple"></div>
                <span>Recognised institute programs</span>
            </div>
        </div>
    </div>

    <!-- Right Login Card Section -->
    <div class="login-section">
        <div class="login-card">
            <h2>Log in to your account</h2>

            <?php if(!empty($error)): ?>
                <div class="alert-box">
                    <span>⚠️ <?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="form-group">
                    <label>Your email</label>
                    <div class="input-wrapper">
                        <input type="email" name="email" required placeholder="Enter your email">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" required placeholder="Enter your password">
                        <span class="eye-icon" id="togglePassword">👁️</span>
                    </div>
                </div>

                <a href="#" class="forgot-password">Forgot password?</a>

                <p class="disclaimer">Your data is secure and never shared with third parties.</p>

                <button type="submit" class="btn-login">Log in</button>
            </form>

            <div class="divider">or</div>

            <div class="footer-action">
                Don't have an account? <a href="register.php">Sign up</a>
            </div>
        </div>
    </div>
</div>

<script>
    const togglePassword = document.querySelector('#togglePassword');
    const password = document.querySelector('#password');

    togglePassword.addEventListener('click', function () {
        // Toggle the input type between password and text
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        
        // Change the emoji based on visibility
        this.textContent = type === 'password' ? '👁️' : '🙈'; 
    });
</script>

</body>
</html>