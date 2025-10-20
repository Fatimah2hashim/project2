<?php
/***** 1) الجلسة والتحقق *****/
session_start();
if (!isset($_SESSION['stable_id'])) { header('Location: stable_login.php'); exit; }
$STABLE_ID = (int) $_SESSION['stable_id'];

/***** 2) الاتصال بقاعدة البيانات عبر dbcon.php مع كتم أي مخرجات *****/
ob_start();
require_once __DIR__ . '/dbcon.php'; // يوفر $conn (mysqli)
ob_end_clean();
if (!$conn) { die('DB connection error'); }
// لا نلمس أي شيء غير جلب مسار الصورة
if (session_status() === PHP_SESSION_NONE) session_start();

$__profile_src__ = 'logo1.png'; // نفس الصورة الحالية كافتراضي

if (!empty($_SESSION['stable_id'])) {
    // استخدمي اتصالك الحالي إن وُجد، وإلا نحاول تضمين dbcon.php بدون لمس شيء
    if (!isset($conn)) {
        $dbconPath = __DIR__ . '/dbcon.php';
        if (file_exists($dbconPath)) {
            require_once $dbconPath; // يُفترض أنه يعرّف $conn (mysqli)
        }
    }

    if (isset($conn) && $conn) {
        $sid = (int)$_SESSION['stable_id'];
        if ($stmt = mysqli_prepare($conn, "SELECT Profile_Image FROM Stable WHERE Stable_ID=?")) {
            mysqli_stmt_bind_param($stmt, 'i', $sid);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                if (!empty($row['Profile_Image'])) {
                    $__profile_src__ = $row['Profile_Image']; // مثال: uploads/stables/stable_3.jpg
                }
            }
            if ($res) mysqli_free_result($res);
            mysqli_stmt_close($stmt);
        }
    }
}

/***** 3) دوال مساعدة *****/
function fetch_all($conn, $sql, $types='', ...$params){
  $stmt = mysqli_prepare($conn, $sql);
  if ($types!=='') mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $rows = [];
  while($r = mysqli_fetch_assoc($res)) $rows[] = $r;
  mysqli_free_result($res);
  mysqli_stmt_close($stmt);
  return $rows;
}
function fetch_one($conn, $sql, $types='', ...$params){
  $stmt = mysqli_prepare($conn, $sql);
  if ($types!=='') mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $row = mysqli_fetch_assoc($res) ?: [];
  mysqli_free_result($res);
  mysqli_stmt_close($stmt);
  return $row;
}
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/***** 4) إضافة رايدر (AUTO_INCREMENT + Stable_ID الحالي) *****/
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rider'])) {

  $Fname  = trim($_POST['first_name'] ?? '');
  $Lname  = trim($_POST['last_name'] ?? '');
  $Age    = trim($_POST['age'] ?? '');
  $Gender = trim($_POST['gender'] ?? '');
  $Phone  = trim($_POST['phone'] ?? '');
  $Email  = trim($_POST['email'] ?? '');

  // تحقق أساسي
  if ($Fname==='' || $Lname==='' || $Age==='' || !ctype_digit($Age) || $Gender==='' || $Phone==='' || $Email==='') {
    $flash = 'Please fill all fields correctly.';
  } else {
    // منع تكرار الهاتف/الإيميل داخل نفس الإسطبل
    $dup = fetch_one($conn,"
      SELECT COUNT(*) AS c FROM Rider 
      WHERE Stable_ID = ? AND (Phone = ? OR Email = ?)
    ", 'iss', $STABLE_ID, $Phone, $Email);

    if ((int)($dup['c'] ?? 0) > 0) {
      $flash = 'Rider with same phone or email already exists in your stable.';
    } else {
      // إدخال (بدون Rider_ID لأنه Auto Increment)
      $stmt = mysqli_prepare($conn, "
        INSERT INTO Rider (Fname, Lname, Age, Gender, Phone, Email, Stable_ID)
        VALUES (?,?,?,?,?,?,?)
      ");

      // bind_param يحتاج متغيّرات تمرّ بالإشارة
      $AgeInt      = (int)$Age;
      $StableIdInt = (int)$STABLE_ID;

      mysqli_stmt_bind_param(
        $stmt,
        'ssisssi',
        $Fname,       // s
        $Lname,       // s
        $AgeInt,      // i
        $Gender,      // s
        $Phone,       // s
        $Email,       // s
        $StableIdInt  // i
      );

      $ok = mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);

      if ($ok) {
        header('Location: rider1.php?added=1'); exit;
      } else {
        $flash = 'Insert failed.';
      }
    }
  }
}

/***** 5) حذف رايدر (ممنوع إذا لديه حجوزات في هذا الإسطبل) *****/
if (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
  $rid = (int) $_GET['delete'];

  // تحقّق أنه يخص هذا الإسطبل
  $owner = fetch_one($conn, "SELECT Stable_ID FROM Rider WHERE Rider_ID = ?", 'i', $rid);
  if (!$owner || (int)$owner['Stable_ID'] !== $STABLE_ID) {
    $flash = 'You can only delete riders in your stable.';
  } else {
    // تأكد ما عنده حجوزات في هذا الإسطبل
    $chk = fetch_one($conn,"
      SELECT COUNT(*) AS c
      FROM Booking b
      JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
      WHERE b.Rider_ID = ? AND s.Stable_ID = ?
    ",'ii',$rid,$STABLE_ID);

    if ((int)($chk['c'] ?? 0) > 0) {
      $flash = 'Cannot delete: rider has bookings in your stable.';
    } else {
      $stmt = mysqli_prepare($conn,"DELETE FROM Rider WHERE Rider_ID = ? AND Stable_ID = ?");
      mysqli_stmt_bind_param($stmt,'ii',$rid,$STABLE_ID);
      mysqli_stmt_execute($stmt);
      mysqli_stmt_close($stmt);
      header('Location: rider1.php?deleted=1'); exit;
    }
  }
}
if (isset($_GET['added']))   $flash = 'Rider added successfully.';
if (isset($_GET['deleted'])) $flash = 'Rider deleted successfully.';

/***** 6) فلاتر العرض *****/
$filter_name   = trim($_GET['name']  ?? '');
$filter_phone  = trim($_GET['phone'] ?? '');
$filter_gender = trim($_GET['gender']?? '');

/***** 7) خيارات الجندر (من رايدرز إسطبلك) *****/
$gender_options = fetch_all($conn,"
  SELECT DISTINCT Gender AS val
  FROM Rider
  WHERE Stable_ID = ? AND Gender <> ''
  ORDER BY val ASC
",'i',$STABLE_ID);

/***** 8) الاستعلام الرئيسي (رايدرز هذا الإسطبل فقط) *****/
$sql = "
  SELECT Rider_ID, Fname, Lname, Age, Gender, Phone, Email
  FROM Rider
  WHERE Stable_ID = ?
";
$types = 'i';
$params = [$STABLE_ID];

if ($filter_name !== '') {
  $sql .= " AND (CONCAT(Fname,' ',Lname) LIKE ?) ";
  $types .= 's'; $params[] = '%'.$filter_name.'%';
}
if ($filter_phone !== '') {
  $sql .= " AND Phone LIKE ? ";
  $types .= 's'; $params[] = '%'.$filter_phone.'%';
}
if ($filter_gender !== '' && strtolower($filter_gender) !== 'all') {
  $sql .= " AND Gender = ? ";
  $types .= 's'; $params[] = $filter_gender;
}

$sql .= " ORDER BY Rider_ID DESC ";
$rows = fetch_all($conn, $sql, $types, ...$params);

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
  <title>StrideHub - Riders</title>
  <link rel="stylesheet" href="style1.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

  <!-- الشريط الجانبي -->
  <div class="sidebar" id="sidebar">
    <a href="dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
    <a href="booking.php"><i class="fa fa-calendar"></i> Booking</a>
    <a href="rider1.php" class="active"><i class="fa fa-users"></i> Riders</a>
    <a href="trainer3.php"><i class="fa fa-chalkboard-teacher"></i> Trainers</a>
    <a href="sub.php"><i class="fa fa-tags"></i> Subscriptions</a>
    <a href="res.php"><i class="fa fa-clipboard-list"></i> Reservations </a>
    <a href="report2.php"><i class="fa fa-file-alt"></i> Report</a>
    <div class="sidebar-bottom">
     <a href="admain.php"><i class="fa fa-user"></i> My Account</a>
     <a href="stable_login.php"><i class="fas fa-sign-out-alt"></i> Signout</a>
    </div>
  </div>

  <!-- الهيدر -->
  <div class="header">
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
      <div class="profile"><img src="<?php echo h($__profile_src__); ?>" alt="Profile" width="80" height="80"></div>
    </div>
  </div>

  <!-- المحتوى -->
  <div class="main">
    <h2>Riders</h2>

    <?php if ($flash !== ''): ?>
      <div class="alert" style="margin-bottom:12px; background:#e6ffed; color:#0b7a33; padding:10px 12px; border-radius:10px;">
        <?php echo h($flash); ?>
      </div>
    <?php endif; ?>

    <!-- الفلاتر (نفس الكلاسات) -->
    <form id="filters" method="get" class="search-box">
      <input type="text" name="name"  placeholder="Rider name"   value="<?php echo h($filter_name); ?>">
      <input type="text" name="phone" placeholder="Mobile number" value="<?php echo h($filter_phone); ?>">
      <select name="gender">
        <option value="">Gender</option>
        <option value="all" <?php echo ($filter_gender==='all'?'selected':''); ?>>All</option>
        <?php foreach ($gender_options as $g): if(($g['val']??'')==='') continue; ?>
          <option value="<?php echo h($g['val']); ?>" <?php echo ($filter_gender===$g['val']?'selected':''); ?>>
            <?php echo h($g['val']); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn">Filter</button>
      <a href="rider1.php" class="btn">Reset</a>
    </form>

    <!-- زر إضافة -->
    <button class="btn" style="margin:10px 0;" onclick="openModal()">+ Add Rider</button>

    <!-- الجدول -->
    <div class="table-box">
      <table>
        <thead>
          <tr>
            <th>Rider ID</th>
            <th>First Name</th>
            <th>Last Name</th>
            <th>Age</th>
            <th>Gender</th>
            <th>Phone Number</th>
            <th>Email</th>
            <th>Delete</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) === 0): ?>
            <tr><td colspan="8" class="empty">---</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo h($r['Rider_ID']); ?></td>
                <td><?php echo h($r['Fname']); ?></td>
                <td><?php echo h($r['Lname']); ?></td>
                <td><?php echo h($r['Age']); ?></td>
                <td><?php echo h($r['Gender']); ?></td>
                <td><?php echo h($r['Phone']); ?></td>
                <td><?php echo h($r['Email']); ?></td>
                <td>
                  <a href="rider1.php?delete=<?php echo (int)$r['Rider_ID']; ?>"
                     onclick="return confirm('Are you sure to delete this rider?');"
                     title="Delete" style="color:#d9534f;">
                    <i class="fa fa-trash"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- مودال إضافة رايدر -->
  <div id="addRiderModal" class="modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); align-items:center; justify-content:center;">
    <div class="modal-card" style="background:#fff; border-radius:16px; padding:20px; min-width:420px; box-shadow:0 20px 50px rgba(0,0,0,.15); position:relative;">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
        
        <button onclick="closeModal()" style="background:none;border:none;font-size:18px;cursor:pointer;">✕</button>
      </div>

      <form method="post" class="modal-form">
        <input type="hidden" name="add_rider" value="1">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <input type="text"   name="first_name" placeholder="First Name" required>
          <input type="text"   name="last_name"  placeholder="Last Name"  required>
          <input type="number" name="age" min="1" max="120" placeholder="Age" required>
          <select name="gender" required>
            <option value="" disabled selected>Gender</option>
            <option>Male</option>
            <option>Female</option>
          </select>
          <input type="text"  name="phone" placeholder="Phone Number" required>
          <input type="email" name="email" placeholder="Email" required>
        </div>
        <div style="margin-top:12px; display:flex; justify-content:flex-end; gap:8px;">
          <button type="button" class="btn" onclick="closeModal()">Cancel</button>
          <button type="submit" class="btn">Add Rider</button>
        </div>
      </form>
    </div>
  </div>

<script>
  function toggleMenu() {
    let sidebar = document.getElementById("sidebar");
    let header = document.querySelector(".header");
    let content = document.querySelector(".main");
    if (sidebar.style.left === "-220px") {
      sidebar.style.left = "0";
      header.style.marginLeft = "220px";
      content.style.marginLeft = "220px";
    } else {
      sidebar.style.left = "-220px";
      header.style.marginLeft = "0";
      content.style.marginLeft = "0";
    }
  }

  // أوتو-سبميت للفلاتر
  document.getElementById('filters').addEventListener('change', function(){ this.submit(); });

  // فتح/إغلاق المودال
  function openModal(){ document.getElementById('addRiderModal').style.display='flex'; }
  function closeModal(){ document.getElementById('addRiderModal').style.display='none'; }
</script>
</body>
</html>
