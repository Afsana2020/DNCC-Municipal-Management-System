<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart DNCC - Citizen Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --primary-dark: #0f2040;
            --accent: #3182ce;
            --accent-light: #4299e1;
            --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f8fafc;
        }

    
        .main-header {
            background: var(--primary);
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            text-decoration: none;
        }

        .logo-icon {
            font-size: 2rem;
            color: #4299e1;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .logo-subtitle {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-top: 2px;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .nav-link {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            padding: 0.5rem 1rem;
            border-radius: 6px;
        }

        .nav-link:hover {
            color: #4299e1;
            background: rgba(255,255,255,0.1);
        }

   
        .dncc-hero {
            background: var(--gradient);
            color: white;
            padding: 80px 0;
            position: relative;
            overflow: hidden;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .dncc-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 100" fill="rgba(255,255,255,0.1)"><polygon points="0,0 1000,50 1000,100 0,100"/></svg>') bottom center no-repeat;
            background-size: cover;
        }

        .hero-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 80px;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .hero-text {
            padding-right: 40px;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 20px;
        }

        .gradient-text {
            background: linear-gradient(45deg, #fff, #e3f2fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .title-sub {
            display: block;
            font-size: 1.5rem;
            font-weight: 300;
            margin-top: 10px;
            opacity: 0.9;
        }

        .hero-description {
            font-size: 1.2rem;
            line-height: 1.6;
            margin-bottom: 40px;
            opacity: 0.9;
        }

        .hero-stats {
            display: flex;
            gap: 40px;
            margin-top: 40px;
        }

        .stat {
            text-align: center;
        }

        .stat-number {
            display: block;
            font-size: 2rem;
            font-weight: 700;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }

 
        .floating-cards {
            display: flex;
            gap: 20px;
            margin-top: 40px;
            justify-content: flex-start;
        }

        .card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            font-weight: 600;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            animation: float 6s ease-in-out infinite;
            min-width: 180px;
            flex: 1;
        }

        .card-1 {
            animation-delay: 0s;
        }

        .card-2 {
            animation-delay: 2s;
        }

        .card-3 {
            animation-delay: 4s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

  
        .auth-sidebar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .auth-form {
            display: none;
        }

        .auth-form.active {
            display: block;
            animation: fadeInUp 0.6s ease-out;
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .form-header p {
            color: #64748b;
            font-size: 1rem;
        }

        .modern-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            z-index: 2;
        }

        .input-group input {
            width: 100%;
            padding: 14px 14px 14px 44px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .input-group input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }

        .input-group label {
            position: absolute;
            left: 44px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            transition: all 0.3s ease;
            pointer-events: none;
            background: white;
            padding: 0 4px;
        }

        .input-group input:focus + label,
        .input-group input:not(:placeholder-shown) + label {
            top: 0;
            font-size: 0.8rem;
            color: var(--accent);
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(49, 130, 206, 0.3);
        }

        .auth-switch {
            text-align: center;
            margin-top: 25px;
            padding-top: 25px;
            border-top: 1px solid #e2e8f0;
        }

        .switch-btn {
            background: none;
            border: none;
            color: var(--accent);
            font-weight: 600;
            cursor: pointer;
            text-decoration: underline;
        }

        .switch-btn:hover {
            color: var(--accent-light);
        }

        /* Footer */
        .main-footer {
            background: var(--primary-dark);
            color: white;
            padding: 50px 0 20px;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 40px;
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .footer-logo .logo-icon {
            font-size: 2rem;
        }

        .footer-logo-text {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .footer-description {
            opacity: 0.8;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .footer-links h4 {
            margin-bottom: 20px;
            color: #4299e1;
        }

        .footer-links ul {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }

        .footer-links a:hover {
            opacity: 1;
        }

        .footer-bottom {
            max-width: 1200px;
            margin: 40px auto 0;
            padding: 20px 20px 0;
            border-top: 1px solid rgba(255,255,255,0.1);
            text-align: center;
            opacity: 0.7;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .hero-content {
                grid-template-columns: 1fr;
                gap: 40px;
                text-align: center;
            }
            
            .hero-text {
                padding-right: 0;
            }
            
            .floating-cards {
                justify-content: center;
                flex-wrap: wrap;
            }
        }

        @media (max-width: 768px) {
            .hero-title {
                font-size: 2.5rem;
            }
            
            .hero-stats {
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .auth-sidebar {
                padding: 30px 20px;
            }
            
            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .nav-links {
                display: none;
            }
            
            .floating-cards {
                flex-direction: column;
                align-items: center;
            }
            
            .card {
                min-width: 250px;
            }
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="main-header">
    <div class="header-content">
        <a href="#" class="logo">
            <i class="fas fa-city logo-icon"></i>
            <div>
                <div class="logo-text">Smart DNCC</div>
                <div class="logo-subtitle">Dhaka North City Corporation</div>
            </div>
        </a>
        <nav class="nav-links">
            <a href="#" class="nav-link">Home</a>
            <a href="#" class="nav-link">About</a>
            <a href="#" class="nav-link">Services</a>
            <a href="#" class="nav-link">Contact</a>
        </nav>
    </div>
</header>

<!-- Login -->
<section class="dncc-hero">
    <div class="hero-content">
        <div class="hero-text">
            <h1 class="hero-title">
                <span class="gradient-text">Smart DNCC</span>
                <span class="title-sub">Dhaka North City Corporation</span>
            </h1>
            <p class="hero-description">
                Report civic issues, track maintenance progress, and contribute to a better Dhaka. 
                Your voice matters in building a smarter, cleaner city through our digital platform.
            </p>
           
            <div class="floating-cards">
                <div class="card card-1">
                    <i class="fas fa-flag"></i>
                    <span>Report Issues</span>
                </div>
                <div class="card card-2">
                    <i class="fas fa-tools"></i>
                    <span>Track Maintenance</span>
                </div>
                <div class="card card-3">
                    <i class="fas fa-chart-line"></i>
                    <span>Real-time Updates</span>
                </div>
            </div>

            <div class="hero-stats">
                <div class="stat">
                    <span class="stat-number">Easy</span>
                    <span class="stat-label">Reporting</span>
                </div>
                <div class="stat">
                    <span class="stat-number">Live</span>
                    <span class="stat-label">Tracking</span>
                </div>
                <div class="stat">
                    <span class="stat-number">Quick</span>
                    <span class="stat-label">Response</span>
                </div>
            </div>
        </div>

        <div class="auth-sidebar">
            <!-- Sign In Form -->
            <div class="auth-form active" id="signIn">
                <div class="form-header">
                    <h2>Welcome Back!</h2>
                    <p>Sign in to your account</p>
                </div>
                <form method="post" action="register.php" class="modern-form">
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <input type="email" name="email" id="signin-email" placeholder=" " required>
                        <label for="signin-email">Email Address</label>
                    </div>
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <input type="password" name="password" id="signin-password" placeholder=" " required>
                        <label for="signin-password">Password</label>
                    </div>
                    <button type="submit" class="btn btn-primary" name="signIn">
                        <span>Sign In</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </form>
                <div class="auth-switch">
                    <p class="text-dark">Don't have an account? <button class="switch-btn" id="showSignUp">Register</button></p>
                </div>
            </div>

            <!-- Sign Up Form -->
            <div class="auth-form" id="signup">
                <div class="form-header">
                    <h2>Join Smart DNCC</h2>
                    <p>Create your citizen account</p>
                </div>
                <form method="post" action="register.php" class="modern-form">
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <input type="text" name="fName" id="fName" placeholder=" " required>
                        <label for="fName">First Name</label>
                    </div>
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <input type="text" name="lName" id="lName" placeholder=" " required>
                        <label for="lName">Last Name</label>
                    </div>
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <input type="email" name="email" id="signup-email" placeholder=" " required>
                        <label for="signup-email">Email Address</label>
                    </div>
                    <div class="input-group">
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <input type="password" name="password" id="signup-password" placeholder=" " required>
                        <label for="signup-password">Create Password</label>
                    </div>
                    <button type="submit" class="btn btn-primary" name="signUp">
                        <span>Create Account</span>
                        <i class="fas fa-user-plus"></i>
                    </button>
                </form>
                <div class="auth-switch">
                   <p class="text-dark">Already have an account? <button class="switch-btn" id="showSignIn">Sign In</button></p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="main-footer">
    <div class="footer-content">
        <div class="footer-info">
            <div class="footer-logo">
                <i class="fas fa-city logo-icon"></i>
                <div class="footer-logo-text">Smart DNCC</div>
            </div>
            <p class="footer-description">
                Dhaka North City Corporation's citizen engagement platform for reporting issues and tracking maintenance work. 
                Building a smarter, more responsive city together.
            </p>
        </div>
        <div class="footer-links">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="#">Home</a></li>
                <li><a href="#">Report Issue</a></li>
                <li><a href="#">Track Status</a></li>
                <li><a href="#">Maintenance</a></li>
            </ul>
        </div>
        <div class="footer-links">
            <h4>Support</h4>
            <ul>
                <li><a href="#">Help Center</a></li>
                <li><a href="#">Contact Us</a></li>
                <li><a href="#">Privacy Policy</a></li>
                <li><a href="#">Terms of Service</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; 2025 A.H. All rights reserved.</p>
    </div>
</footer>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const showSignUp = document.getElementById('showSignUp');
        const showSignIn = document.getElementById('showSignIn');
        const signInForm = document.getElementById('signIn');
        const signUpForm = document.getElementById('signup');

        showSignUp.addEventListener('click', function() {
            signInForm.classList.remove('active');
            signUpForm.classList.add('active');
        });

        showSignIn.addEventListener('click', function() {
            signUpForm.classList.remove('active');
            signInForm.classList.add('active');
        });

        const cards = document.querySelectorAll('.card');
        cards.forEach((card, index) => {
            card.style.animationDelay = `${index * 2}s`;
        });
    });
</script>

</body>
</html>