<?php
/* =========================================================
   Sahwa – Stable Registration
   يسجّل إسطبل جديد في جدول Stable
   الأعمدة: Stable_name, Stable_location, Stable_contactNumber, Email, Password
   ملاحظة: نخزّن كلمة المرور Plain مؤقتًا حسب طلبك (يمكن لاحقًا استخدام password_hash)
   ========================================================= */

session_start();

/* 1) الاتصال بقاعدة البيانات */
$host = "localhost";
$user = "root";
$pass = "";          // كلمة مرور MySQL إن وجدت
$db   = "SahwaDB";   // اسم قاعدة البيانات الصحيحة
$port = 3307;        // منفذ MySQL عندك

$conn = new mysqli($host, $user, $pass, $db, $port);
$conn->set_charset("utf8mb4");
if ($conn->connect_error) {
  die("DB Connection failed: " . $conn->connect_error);
}

/* 2) متغيرات لرسائل النجاح/الخطأ */
$errors = [];
$success = "";

/* 3) معالجة النموذج عند الإرسال */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  // استلام الحقول (تشذيب المسافات)
  $stableName  = trim($_POST["stable_name"] ?? "");
  $location    = trim($_POST["location"] ?? "");
  $contact     = trim($_POST["contact"] ?? "");
  $email       = trim($_POST["email"] ?? "");
  $password    = $_POST["password"] ?? "";
  $confirmPass = $_POST["confirm_password"] ?? "";

  // 3.1 تحققات بسيطة
  if ($stableName === "") $errors[] = "الرجاء إدخال اسم الإسطبل.";
  if ($location === "")   $errors[] = "الرجاء إدخال الموقع (المدينة/الحي).";
  if ($contact === "")    $errors[] = "الرجاء إدخال رقم التواصل.";
  if ($email === "")      $errors[] = "الرجاء إدخال البريد الإلكتروني.";
  if ($password === "")   $errors[] = "الرجاء إدخال كلمة المرور.";
  if ($confirmPass === "")$errors[] = "الرجاء تأكيد كلمة المرور.";
  if ($password !== "" && $confirmPass !== "" && $password !== $confirmPass) {
    $errors[] = "كلمتا المرور غير متطابقتين.";
  }

  // 3.2 التحقق من عدم تكرار اسم الإسطبل
  if (empty($errors)) {
    $chk = $conn->prepare("SELECT 1 FROM Stable WHERE Stable_name = ? LIMIT 1");
    if (!$chk) die("SQL prepare error: " . $conn->error);
    $chk->bind_param("s", $stableName);
    $chk->execute();
    $exists = $chk->get_result()->num_rows > 0;
    $chk->close();

    if ($exists) {
      $errors[] = "اسم الإسطبل مستخدم مسبقًا. الرجاء اختيار اسم آخر.";
    }
  }

  // 3.3 إدخال السجل إذا لا يوجد أخطاء
  if (empty($errors)) {
    // ملاحظة: نخزّن كلمة المرور نص عادي مؤقتًا حسب طلبك
    // لتحويلها لاحقًا إلى هاش: $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $conn->prepare("
      INSERT INTO Stable (Stable_name, Stable_location, Stable_contactNumber, Email, Password)
      VALUES (?, ?, ?, ?, ?)
    ");
    if (!$ins) die("Insert prepare error: " . $conn->error);
    $ins->bind_param("sssss", $stableName, $location, $contact, $email, $password);
    $ok = $ins->execute();
    $ins->close();

    if ($ok) {
      $success = "تم إنشاء حساب الإسطبل بنجاح.";
      // ممكن تخزنين السيشن وتحوّلين إلى داشبورد الإسطبل:
      // $_SESSION['stable_id'] = $conn->insert_id;
      // $_SESSION['stable_name'] = $stableName;
      // header("Location: stable_dashboard.php"); exit;
    } else {
      $errors[] = "تعذّر إنشاء الحساب. جرّبي لاحقًا.";
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sahwa – Create Stable Account</title>
  <link rel="stylesheet" href="styles.css" />
</head>

<body class="page-default">

  <header class="topbar">
    <div class="brand">
      <div class="logo-dot" aria-hidden="true"></div>
      <span class="brand-name">Sahwa</span>
    </div>
  </header>

  <main class="center">
    <section class="card" aria-labelledby="title">
      <h1 id="title" class="card-title">Create a Stable Account</h1>

      <!-- رسائل التنبيه -->
      <div class="messages">
        <?php if (!empty($success)): ?>
          <div class="msg-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php foreach ($errors as $e): ?>
          <div class="msg-error">• <?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      </div>

      <!-- الفورم: نرسل لنفس الصفحة (POST) -->
      <form class="form" method="post">
        <!-- Stable Name -->
        <label class="field">
          <span class="label">Stable Name</span>
          <input class="input" type="text" name="stable_name" placeholder="مثال: Al Qafilah Stable" required />
        </label>

        <!-- Location -->
        <label class="field">
          <span class="label">Location</span>
          <input class="input" type="text" name="location" placeholder="المدينة / الحي" required />
        </label>

        <!-- Contact Number -->
        <label class="field">
          <span class="label">Contact Number</span>
          <input class="input" type="tel" name="contact" placeholder="05XXXXXXXX" required />
        </label>

        <!-- Email -->
        <label class="field">
          <span class="label">Email</span>
          <input class="input" type="email" name="email" placeholder="stable@example.com" required />
        </label>

        <!-- Password -->
        <label class="field">
          <span class="label">Password</span>
          <input class="input" id="password" type="password" name="password" placeholder="********" required />
        </label>

        <!-- Confirm Password -->
        <label class="field">
          <span class="label">Confirm Password</span>
          <input class="input" id="confirm_password" type="password" name="confirm_password" placeholder="********" required />
        </label>

        <!-- Show/Hide -->
        <label style="display:flex; align-items:center; gap:8px; margin-top:6px;">
          <input type="checkbox" id="showPasswords" />
          <span>Show password</span>
        </label>

        <button class="btn-primary" type="submit">Create an Account</button>
      </form>

      <div class="divider"><span class="divider-text">Or</span></div>

      <p class="login">
        Already have a stable account?
        <a class="login-link" href="stable_login.php">Log In</a>
      </p>
    </section>
  </main>

<script>
  // تبديل عرض/إخفاء كلمات السر
  const pwd = document.getElementById('password');
  const confirmPwd = document.getElementById('confirm_password');
  const show = document.getElementById('showPasswords');
  show.addEventListener('change', (e) => {
    const type = e.target.checked ? 'text' : 'password';
    const selP = [pwd.selectionStart, pwd.selectionEnd];
    const selC = [confirmPwd.selectionStart, confirmPwd.selectionEnd];
    pwd.type = type; confirmPwd.type = type;
    try { pwd.setSelectionRange(...selP); } catch(_) {}
    try { confirmPwd.setSelectionRange(...selC); } catch(_) {}
  });
</script>
</body>
</html>
