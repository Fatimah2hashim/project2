<?php

session_start();

/* 1) الاتصال بقاعدة البيانات */
$host = "localhost";
$user = "root";
$pass = "";          
$db   = "SahwaDB";   //اسم قاعدة البيانات 
$port = 3307;        // منفذ MySQL في XAMPP 

$conn = new mysqli($host, $user, $pass, $db, $port);
$conn->set_charset('utf8mb4');
if ($conn->connect_error) {
  die("DB Connection failed: " . $conn->connect_error);
}

/* 2) متغيرات للرسائل */
$error = "";

/* 3) معالجة POST: التحقق من بيانات الدخول */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // يستقبل (اسم إسطبل أو إيميل) + كلمة المرور
  $loginKey = trim($_POST['name'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($loginKey === '' || $password === '') {
    $error = "الرجاء إدخال الاسم/الإيميل وكلمة المرور.";
  } else {
    /* نسمح بالدخول عبر Stable_name أو Email */
    $sql = "
      SELECT Stable_ID, Stable_name, Email, Password
      FROM Stable
      WHERE (Stable_name = ? OR Email = ?)
        AND Password = ?
      LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      $error = "SQL error: " . $conn->error;
    } else {
      $stmt->bind_param("sss", $loginKey, $loginKey, $password);
      $stmt->execute();
      $res = $stmt->get_result();

      if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc();

        // نجاح الدخول: نحفظ بيانات الجلسة ونحوّل للداشبورد
        $_SESSION['Password']   = (int)$row['Password'];
        $_SESSION['stable_name'] = $row['Stable_name'];
        $_SESSION['stable_id']   = $row['Stable_ID'];

        // ملاحظة: فضّلي صفحة PHP للداشبورد كي تقدرين تقرئين السيشن
        header("Location:dashboard.php");
        exit();
      } else {
        $error = "بيانات الدخول غير صحيحة.";
      }
      $stmt->close();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sahwa — Login</title>

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
  <!-- Styles -->
  <link rel="stylesheet" href="styles.css">
  
</head>
<body>
  <!-- Top brand bar -->
  <header class="topbar">
    <div class="brand">
      <img src="logo.png" alt="sahwa logo" class="brand-icon">
      <span class="brand-name">Sahwa</span>
    </div>
  </header>

  <!-- Background + overlay (اختياري) -->
  <div class="bg" role="img" aria-label="running horses background"></div>
  <div class="overlay"></div>

  <!-- Centered login card -->
  <main class="center">
    <section class="card" aria-labelledby="loginTitle">
      <h1 id="loginTitle" class="sr-only">Log In</h1>

      <!-- رسالة الخطأ (إن وجدت) -->
      <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- نرسل لنفس الصفحة -->
      <form class="form" action="stable_login.php" method="post" novalidate>
        <label class="field">
          <span class="label">Stable Name or Email</span>
          <input class="input" type="text" name="name" placeholder="Al Qafilah Stable or stable@example.com" required>
        </label>

        <label class="field">
          <span class="label">Password</span>
          <div class="pwd-wrap">
            <input class="input" id="password" type="password" name="password" placeholder="********" required />
            <!-- زر إظهار/إخفاء كلمة المرور -->
            <button type="button" class="pwd-toggle" id="togglePwd" aria-label="Show password">
              <!-- عين مفتوحة -->
              <svg class="icon-eye eye-open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
              <!-- عين مشطوبة -->
              <svg class="icon-eye eye-closed" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17.94 17.94A10.93 10.93 0 0 1 12 20c-7 0-11-8-11-8a21.77 21.77 0 0 1 5.06-6.88"/>
                <path d="M1 1l22 22"/>
              </svg>
            </button>
          </div>
        </label>

        <button type="submit" class="btn-login">Login</button>
      </form>
    </section>
  </main>

  <script>
  // JS فقط لتبديل عرض/إخفاء كلمة المرور
  document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('togglePwd');
    const pwd = document.getElementById('password');
    if (toggleBtn && pwd) {
      toggleBtn.addEventListener('click', () => {
        const isText = pwd.type === 'text';
        pwd.type = isText ? 'password' : 'text';
        toggleBtn.classList.toggle('active', !isText);
        toggleBtn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
      });
    }
  });

  </script>
</body>
</html>
