<?php
/******************************
 * admain.php (My Account)
 * - يجلب بيانات الإسطبل من DB
 * - رسالة ترحيب مخصصة
 * - تحديث البيانات + تشفير كلمة المرور (password_hash)
 * - رفع/تحديث صورة البروفايل (Profile_Image)
 * - تزويد الهيدر بعدّاد الإشعارات وصورة البروفايل
 ******************************/

// بدء الجلسة + التأكد من تسجيل دخول الإسطبل
session_start(); // تشغيل الجلسة
if (!isset($_SESSION['stable_id'])) { // إذا مافيه تسجيل دخول
  header('Location: stable_login.php'); // رجّعه لصفحة تسجيل الدخول
  exit;
}
$STABLE_ID = (int) $_SESSION['stable_id']; // رقم الإسطبل الحالي

// ربط قاعدة البيانات (مع كتم أي echo من dbcon.php)
ob_start();                 // تشغيل buffer لمنع طباعة أي نص
require_once __DIR__ . '/dbcon.php'; // ملف الاتصال ($conn)
ob_end_clean();             // تنظيف البفر (ما يظهر "YES" من dbcon)
if (!$conn) { die('DB connection error'); } // تأكيد الاتصال

// دالة بسيطة للتعقيم قبل الطباعة في HTML
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ─────────────────────────────────────────────
// 1) جلب بيانات الإسطبل الحالية (تشمل Profile_Image)
// ─────────────────────────────────────────────
$stable = [
  'Stable_name'          => '',
  'Stable_location'      => '',
  'Stable_contactNumber' => '',
  'Email'                => '',
  'Password'             => '',  // هنا هاش كلمة المرور (لن نعرضه)
  'Profile_Image'        => ''   // مسار صورة البروفايل
];

$stmt = mysqli_prepare($conn, "SELECT Stable_name, Stable_location, Stable_contactNumber, Email, Password, Profile_Image FROM Stable WHERE Stable_ID = ?");
mysqli_stmt_bind_param($stmt, 'i', $STABLE_ID); // ربط رقم الإسطبل
mysqli_stmt_execute($stmt);                      // تنفيذ الاستعلام
$res = mysqli_stmt_get_result($stmt);            // جلب النتائج
if ($row = mysqli_fetch_assoc($res)) {           // لو فيه نتيجة
  $stable = $row;                                 // خزّن البيانات
}
mysqli_free_result($res);                        // تحرير النتيجة
mysqli_stmt_close($stmt);                        // إغلاق الستيتمنت

// ─────────────────────────────────────────────
// 2) معالجة التحديث (عند ضغط زر Save Changes)
// ─────────────────────────────────────────────
$flash = ''; // رسالة نجاح/فشل للتغذية الراجعة

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_stable'])) {
  // قراءة الحقول من الفورم
  $name     = trim($_POST['Stable_name'] ?? '');           // اسم الإسطبل
  $loc      = trim($_POST['Stable_location'] ?? '');       // الموقع
  $phone    = trim($_POST['Stable_contactNumber'] ?? '');  // الجوال
  $email    = trim($_POST['Email'] ?? '');                 // الإيميل
  $pass_in  = trim($_POST['Password'] ?? '');              // كلمة المرور الجديدة (إن وُجدت)

  // تحقق أساسي: الحقول المطلوبة
  if ($name==='' || $loc==='' || $phone==='' || $email==='') {
    $flash = 'Please fill all required fields.';
  } else {

    // 2-أ) تشفير كلمة المرور إن المستخدم أدخل كلمة جديدة، وإلا نُبقي القديم
    if ($pass_in !== '') {
      $new_hashed_password = password_hash($pass_in, PASSWORD_DEFAULT); // إنشاء هاش آمن
    } else {
      $new_hashed_password = $stable['Password']; // احتفظ بالهاش الحالي
    }

    // 2-ب) معالجة رفع صورة البروفايل (اختياري)
    $profilePath = $stable['Profile_Image']; // المسار الحالي (إن وُجد)
    if (!empty($_FILES['profile_image']['name']) && is_uploaded_file($_FILES['profile_image']['tmp_name'])) {
      $mime = @mime_content_type($_FILES['profile_image']['tmp_name']);    // نوع الملف
      $ext  = ($mime === 'image/png') ? 'png' : (($mime === 'image/jpeg') ? 'jpg' : '');
      if ($ext !== '') {                                                   // قبول PNG/JPG فقط
        $destDir  = __DIR__ . '/uploads/stables';                          // مجلد الرفع (داخل Admin/uploads/stables)
        if (!is_dir($destDir)) { @mkdir($destDir, 0777, true); }           // إن ماكان موجود انشئه
        $destRel  = 'uploads/stables/stable_'.$STABLE_ID.'.'.$ext;         // المسار النسبي (يُخزن في DB)
        $destFull = __DIR__ . '/'. $destRel;                                // المسار الكامل على السيرفر
        foreach (glob(__DIR__ . '/uploads/stables/stable_'.$STABLE_ID.'.*') as $old) { @unlink($old); } // حذف أي صورة قديمة باختلاف الامتداد
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destFull)) {
          $profilePath = $destRel;                                         // حدّث المسار النسبي الجديد
        }
      }
    }

    // 2-ج) تنفيذ UPDATE لجدول Stable  (مع Profile_Image)
    $stmt = mysqli_prepare($conn, "
      UPDATE Stable
      SET Stable_name=?, Stable_location=?, Stable_contactNumber=?, Email=?, Password=?, Profile_Image=?
      WHERE Stable_ID=?
    ");
    mysqli_stmt_bind_param($stmt, 'ssssssi', $name, $loc, $phone, $email, $new_hashed_password, $profilePath, $STABLE_ID);
    $ok = mysqli_stmt_execute($stmt); // تنفيذ التحديث
    mysqli_stmt_close($stmt);         // إغلاق الستيتمنت

    if ($ok) {
      // تحديث النسخة المحلية للعرض مباشرة
      $stable['Stable_name']          = $name;
      $stable['Stable_location']      = $loc;
      $stable['Stable_contactNumber'] = $phone;
      $stable['Email']                = $email;
      $stable['Password']             = $new_hashed_password;
      $stable['Profile_Image']        = $profilePath;

      $flash = 'Your account information has been updated successfully.';
    } else {
      $flash = 'Update failed. Please try again.';
    }
  }
}

// ─────────────────────────────────────────────
// 3) بيانات الهيدر الموحّدة (صورة البروفايل + إشعارات الحجوزات)
//    * انسخي هذا القسم كما هو في بقية الصفحات بعد الاتصال والجلسة *
// ─────────────────────────────────────────────

// مسار صورة البروفايل (fallback على الشعار إن ما فيه صورة)
$profile_img = (!empty($stable['Profile_Image'])) ? $stable['Profile_Image'] : 'logo1.png';

// عداد إشعارات الطلبات الجديدة (pending اليوم) لإسطبلك
// عداد إشعارات الطلبات الجديدة (pending اليوم أو في المستقبل) لإسطبلك
// هذا هو الاستعلام الذي تم تصحيحه ليتضمن التاريخ الحالي أو المستقبلي (>= CURDATE())
$stmtN = mysqli_prepare($conn, "
  SELECT COUNT(*) AS cnt
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  WHERE s.Stable_ID = ? 
    AND b.Status = 'pending' 
    AND b.Date >= CURDATE()
");
mysqli_stmt_bind_param($stmtN, 'i', $STABLE_ID);
mysqli_stmt_execute($stmtN);
$resN = mysqli_stmt_get_result($stmtN);
$notif_count = 0;
if ($n = mysqli_fetch_assoc($resN)) $notif_count = (int)$n['cnt'];
mysqli_free_result($resN);
mysqli_stmt_close($stmtN);

// (اختياري) آخر 5 طلبات جديدة لعرضها كقائمة منسدلة
$latest = [];
$stmtL = mysqli_prepare($conn, "
  SELECT b.Booking_ID, b.Time, CONCAT(r.Fname,' ',r.Lname) AS rider, b.Class_Type
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  JOIN Rider r ON r.Rider_ID = b.Rider_ID
  WHERE s.Stable_ID = ? AND b.Status='pending' AND b.Date >= CURDATE()
  ORDER BY b.Booking_ID DESC
  LIMIT 5
");
mysqli_stmt_bind_param($stmtL, 'i', $STABLE_ID);
mysqli_stmt_execute($stmtL);
$resL = mysqli_stmt_get_result($stmtL);
while($row = mysqli_fetch_assoc($resL)) $latest[] = $row;
mysqli_free_result($resL);
mysqli_stmt_close($stmtL);


?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Sahwa - My Account</title>
  <link rel="stylesheet" href="style1.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

  <!-- الشريط الجانبي -->
  <div class="sidebar" id="sidebar">
    <a href="dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
    <a href="booking.php"><i class="fa fa-calendar"></i> Booking</a>
    <a href="rider1.php"><i class="fa fa-users"></i> Riders</a>
    <a href="trainer3.php"><i class="fa fa-chalkboard-teacher"></i> Trainers</a>
    <a href="sub.php"><i class="fa fa-tags"></i> Subscriptions</a>
    <a href="res.php"><i class="fa fa-clipboard-list"></i> Reservations</a>
    <a href="report2.php"><i class="fa fa-file-alt"></i> Report</a>
    <div class="sidebar-bottom">
      <a href="admain.php" class="active"><i class="fa fa-user"></i> My Account</a>
      <a href="stable_login.php"><i class="fas fa-sign-out-alt"></i> Signout</a>
    </div>
  </div>

  <!-- الهيدر -->
  <div class="header">
    <!-- زر القائمة -->
    <i class="fas fa-bars menu-btn" onclick="toggleMenu()" style="cursor:pointer; font-size:20px;"></i>
    <h1 class="logo">Sahwa</h1>

    <div class="icons">
      <div></div>

      <!-- أيقونة التنبيهات مع الشارة -->
      <div class="notif">
        <i class="fas fa-bell"></i>
        <?php if ($notif_count > 0): ?>
          <span class="badge"><?php echo $notif_count; ?></span>
        <?php endif; ?>

        <!-- قائمة منسدلة (اختيارية) لآخر 5 حجوزات جديدة -->
        <?php if (!empty($latest)): ?>
          <div class="notif-menu">
            <?php foreach ($latest as $it): ?>
              <div class="notif-item">
                <strong>#<?php echo (int)$it['Booking_ID']; ?></strong>
                — <?php echo h($it['rider']); ?> (<?php echo h($it['Class_Type']); ?> @ <?php echo h($it['Time']); ?>)
              </div>
            <?php endforeach; ?>
            <a class="notif-more" href="booking.php">View all</a>
          </div>
        <?php endif; ?>
      </div>

      <!-- صورة البروفايل (ديناميكية) -->
      <div class="profile">
        <img src="<?php echo h($profile_img); ?>" alt="Profile" width="80" height="80">
      </div>
    </div>
  </div>

  <!-- المحتوى -->
  <div class="main">
    <!-- رسالة ترحيب -->
    <div class="alert" style="margin-bottom:12px; background:#eef6ff; color:#084298; padding:12px 14px; border-radius:12px;">
      <strong>Welcome back, <?php echo h($stable['Stable_name']); ?>!</strong> This is your stable account overview.
    </div>

    <?php if ($flash !== ''): ?>
      <div class="alert" style="margin-bottom:12px; background:#e6ffed; color:#0b7a33; padding:10px 12px; border-radius:10px;">
        <?php echo h($flash); ?>
      </div>
    <?php endif; ?>

    <!-- بطاقة معلومات الإسطبل الحالية -->
    <div class="table-box" style="margin-bottom:14px;">
      <table>
        <thead>
          <tr>
            <th>Stable Name</th>
            <th>Location</th>
            <th>Contact Number</th>
            <th>Email</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><?php echo h($stable['Stable_name']); ?></td>
            <td><?php echo h($stable['Stable_location']); ?></td>
            <td><?php echo h($stable['Stable_contactNumber']); ?></td>
            <td><?php echo h($stable['Email']); ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- نموذج التحديث -->
    <div class="table-box" style="padding:18px;">
      <h3 style="margin:0 0 12px 0;">Update Account Information</h3>
      <!-- مهم: enctype لرفع الملفات -->
      <form method="post" enctype="multipart/form-data" class="account-form">
        <input type="hidden" name="update_stable" value="1">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <div>
            <label>Stable Name</label>
            <input type="text" name="Stable_name" value="<?php echo h($stable['Stable_name']); ?>" required>
          </div>
          <div>
            <label>Location</label>
            <input type="text" name="Stable_location" value="<?php echo h($stable['Stable_location']); ?>" required>
          </div>
          <div>
            <label>Contact Number</label>
            <input type="text" name="Stable_contactNumber" value="<?php echo h($stable['Stable_contactNumber']); ?>" required>
          </div>
          <div>
            <label>Email</label>
            <input type="email" name="Email" value="<?php echo h($stable['Email']); ?>" required>
          </div>

          <!-- كلمة المرور الجديدة (اتركه فارغًا لإبقاء الحالية) -->
          <div style="grid-column:1 / span 2;">
            <label>New Password (leave blank to keep current)</label>
            <input type="password" name="Password" placeholder="Enter new password to change it">
          </div>

          <!-- رفع صورة البروفايل -->
          <div style="grid-column:1 / span 2;">
            <label>Profile image (JPG/PNG)</label>
            <input type="file" name="profile_image" accept="image/png,image/jpeg">
            <small>Leave empty to keep current image.</small>
          </div>
        </div>

        <div style="margin-top:12px; display:flex; gap:8px; justify-content:flex-end;">
          <button type="submit" class="btn">Save Changes</button>
          <a href="admain.php" class="btn">Cancel</a>
        </div>
      </form>
    </div>
  </div>

<script>
  // فتح/إغلاق الشريط الجانبي
  function toggleMenu() {
    let sidebar = document.getElementById("sidebar");
    let header  = document.querySelector(".header");
    let content = document.querySelector(".main");
    if (sidebar.style.left === "-220px") {
      sidebar.style.left = "0"; header.style.marginLeft = "220px"; content.style.marginLeft = "220px";
    } else {
      sidebar.style.left = "-220px"; header.style.marginLeft = "0"; content.style.marginLeft = "0";
    }
  }
</script>
</body>
</html>
