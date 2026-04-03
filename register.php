<?php 
include 'connect.php';

if(isset($_POST['signUp'])){
    $firstName=$_POST['fName'];
    $lastName=$_POST['lName'];
    $email=$_POST['email'];
    $password=$_POST['password'];
    $password=$password;

     $checkEmail="SELECT * From users where email='$email'";
     $result=$conn->query($checkEmail);
     if($result->num_rows>0){
        echo "Email Address Already Exists !";
     }
     else{
        $insertQuery="INSERT INTO users(firstName,lastName,email,password,role)
                       VALUES ('$firstName','$lastName','$email','$password','citizen')";
            if($conn->query($insertQuery)==TRUE){
                header("location: index.php");
            }
            else{
                echo "Error:".$conn->error;
            }
     }
}

if(isset($_POST['signIn'])){
   $email=$_POST['email'];
   $password=$_POST['password'];
   $password=$password ;
   
   $sql="SELECT * FROM users WHERE email='$email' and password='$password'";
   $result=$conn->query($sql);
   if($result->num_rows>0){
    session_start();
    $row=$result->fetch_assoc();
    $_SESSION['email']=$row['email'];
    $_SESSION['role']=$row['role'];
    $_SESSION['id']=$row['id'];
    header("Location: homepage.php");
    exit();
   }
   else{
    
    showErrorPage();
    exit();
   }
}

function showErrorPage() {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Failed - Smart DNCC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #1a365d;
            --primary-dark: #0f2040;
            --accent: #3182ce;
            --accent-light: #4299e1;
            --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 100" fill="rgba(255,255,255,0.1)"><polygon points="0,0 1000,50 1000,100 0,100"/></svg>') bottom center no-repeat;
            background-size: cover;
        }
        
        .error-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 50px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 500px;
            width: 90%;
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            z-index: 2;
        }
        
        .error-icon {
            font-size: 4rem;
            background: linear-gradient(135deg, #e53e3e, #c53030);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
            animation: shake 0.5s ease-in-out;
        }
        
        .error-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 15px;
        }
        
        .error-message {
            color: #64748b;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .btn-primary {
            background: var(--gradient);
            color: white;
            border: none;
            padding: 14px 32px;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
            color: white;
            text-decoration: none;
        }
        
        .error-details {
            background: rgba(254, 215, 215, 0.3);
            border: 1px solid rgba(254, 215, 215, 0.5);
            border-radius: 12px;
            padding: 20px;
            margin-top: 25px;
            text-align: left;
            backdrop-filter: blur(10px);
        }
        
        .error-details h4 {
            color: var(--primary);
            margin-bottom: 12px;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .error-details h4 i {
            color: var(--accent);
        }
        
        .error-details ul {
            color: #4a5568;
            padding-left: 20px;
            margin-bottom: 0;
        }
        
        .error-details li {
            margin-bottom: 8px;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        
        .dncc-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 30px;
            color: var(--primary);
        }
        
        .dncc-brand i {
            font-size: 2rem;
            color: var(--accent);
        }
        
        .dncc-brand-text {
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
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
        
        .error-container {
            animation: fadeInUp 0.6s ease-out;
        }
        
        @media (max-width: 480px) {
            .error-container {
                padding: 30px 20px;
            }
            
            .error-title {
                font-size: 1.5rem;
            }
            
            .error-icon {
                font-size: 3rem;
            }
            
            .dncc-brand {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
   
        <div class="dncc-brand">
            <i class="fas fa-city"></i>
            <div class="dncc-brand-text">Smart DNCC</div>
        </div>
        
        <div class="error-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        
        <h1 class="error-title">Login Failed</h1>
        
        <p class="error-message">
            We couldn't sign you in. Please check your email and password and try again.
        </p>
        
        <a href="index.php" class="btn-primary">
            <i class="fas fa-arrow-left"></i>
            Try Again
        </a>
        
        <div class="error-details">
            <h4>
                <i class="fas fa-lightbulb"></i>
                Quick Tips:
            </h4>
            <ul>
                <li>Check if your email address is correct</li>
                <li>Ensure your password is entered properly</li>
                <li>Make sure Caps Lock is turned off</li>
                <li>Contact support if you've forgotten your password</li>
            </ul>
        </div>
    </div>

    <script>
     
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.querySelector('.btn-primary');
            
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>
<?php
}
?>