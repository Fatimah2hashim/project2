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

/***** 2.1) جلب مسار صورة البروفايل للإسطبل *****/
$__profile_default__ = 'logo1.png'; // لا نغيّر التصميم: نفس الصورة الحالية كافتراضي
$__profile_src__     = $__profile_default__;

$__stmt = mysqli_prepare($conn, "SELECT Profile_Image FROM Stable WHERE Stable_ID = ?");
mysqli_stmt_bind_param($__stmt, 'i', $STABLE_ID);
mysqli_stmt_execute($__stmt);
$__res = mysqli_stmt_get_result($__stmt);
if ($__row = mysqli_fetch_assoc($__res)) {
  if (!empty($__row['Profile_Image'])) {
    $__profile_src__ = $__row['Profile_Image'];
  }
}
mysqli_free_result($__res);
mysqli_stmt_close($__stmt);

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
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/***** 4) قراءة قيم الفلاتر من GET *****/
$filter_customer = trim($_GET['customer'] ?? '');
$filter_status   = trim($_GET['status']   ?? '');
$filter_service  = trim($_GET['service']  ?? '');
$filter_provider = trim($_GET['provider'] ?? '');
$filter_q        = trim($_GET['q']        ?? ''); // بحث عام

/***** 5) خيارات الفلاتر (ديناميكية) *****/
$status_options = fetch_all($conn, "
  SELECT DISTINCT b.Status AS val
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  WHERE s.Stable_ID = ?
  ORDER BY val ASC
", 'i', $STABLE_ID);

$service_options = fetch_all($conn, "
  SELECT DISTINCT b.Class_Type AS val
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  WHERE s.Stable_ID = ?
  ORDER BY val ASC
", 'i', $STABLE_ID);

$provider_options = fetch_all($conn, "
  SELECT DISTINCT t.Trainer_ID AS id, CONCAT(t.Fname,' ',t.Lname) AS name
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  JOIN Trainer t ON t.Trainer_ID = b.Trainer_ID
  WHERE s.Stable_ID = ?
  ORDER BY name ASC
", 'i', $STABLE_ID);

/***** 6) الاستعلام الرئيسي مع تطبيق الفلاتر *****/
$sql = "
  SELECT 
    b.Booking_ID,
    b.Date,
    b.Time,
    b.Status,
    b.Class_Type,
    b.Class_Number,
    CONCAT(t.Fname,' ',t.Lname) AS Trainer_Name,
    CONCAT(r.Fname,' ',r.Lname) AS Rider_Name,
    b.Subscription_ID
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  JOIN Trainer t ON t.Trainer_ID = b.Trainer_ID
  JOIN Rider   r ON r.Rider_ID   = b.Rider_ID
  WHERE s.Stable_ID = ?
";
$types = 'i';
$params = [$STABLE_ID];

if ($filter_customer !== '') {
  $sql .= " AND (CONCAT(r.Fname,' ',r.Lname) LIKE ?) ";
  $types .= 's'; $params[] = '%'.$filter_customer.'%';
}
if ($filter_status !== '' && strtolower($filter_status) !== 'all') {
  $sql .= " AND b.Status = ? ";
  $types .= 's'; $params[] = $filter_status;
}
if ($filter_service !== '' && strtolower($filter_service) !== 'all') {
  $sql .= " AND b.Class_Type = ? ";
  $types .= 's'; $params[] = $filter_service;
}
if ($filter_provider !== '' && strtolower($filter_provider) !== 'all') {
  $sql .= " AND t.Trainer_ID = ? ";
  $types .= 'i'; $params[] = (int)$filter_provider;
}
if ($filter_q !== '') {
  $sql .= " AND (
      CONCAT(r.Fname,' ',r.Lname) LIKE ?
      OR CONCAT(t.Fname,' ',t.Lname) LIKE ?
      OR b.Class_Type LIKE ?
      OR b.Status LIKE ?
    ) ";
  $types .= 'ssss';
  $like = '%'.$filter_q.'%';
  array_push($params, $like, $like, $like, $like);
}

$sql .= " ORDER BY b.Date DESC, b.Time DESC, b.Booking_ID DESC ";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = [];
while($row = mysqli_fetch_assoc($res)) $rows[] = $row;
mysqli_free_result($res);
mysqli_stmt_close($stmt);


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
  <title>StrideHub - Booking</title>
  <link rel="stylesheet" href="style1.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

  <!-- الشريط الجانبي -->
  <div class="sidebar" id="sidebar">
    <a href="dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
    <a href="booking.php" class="active"><i class="fa fa-calendar"></i> Booking</a>
    <a href="rider1.php"><i class="fa fa-users"></i> Riders</a>
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
    <h2>Booking Table</h2>

    
    <form id="filters" method="get" class="search-box">
      <input type="text" name="customer" placeholder="Customer" value="<?php echo h($filter_customer); ?>">
      
      <select name="status">
        <option value="">Status</option>
        <option value="all" <?php echo ($filter_status==='all'?'selected':''); ?>>All</option>
        <?php foreach ($status_options as $opt): ?>
          <option value="<?php echo h($opt['val']); ?>" <?php echo ($filter_status===$opt['val']?'selected':''); ?>>
            <?php echo h($opt['val']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="service">
        <option value="">Service</option>
        <option value="all" <?php echo ($filter_service==='all'?'selected':''); ?>>All</option>
        <?php foreach ($service_options as $opt): ?>
          <option value="<?php echo h($opt['val']); ?>" <?php echo ($filter_service===$opt['val']?'selected':''); ?>>
            <?php echo h($opt['val']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="provider">
        <option value="">Service Provider</option>
        <option value="all" <?php echo ($filter_provider==='all'?'selected':''); ?>>All</option>
        <?php foreach ($provider_options as $opt): ?>
          <option value="<?php echo (int)$opt['id']; ?>" <?php echo ($filter_provider==(string)$opt['id']?'selected':''); ?>>
            <?php echo h($opt['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      

      <button type="submit" class="btn">Filter</button>
      <a href="booking.php" class="btn">Reset</a>
    </form>

    <!-- الجدول (بدون inline styles) -->
    <div class="table-box">
      <table>
        <thead>
          <tr>
            <th>Booking_ID</th>
            <th>Date</th>
            <th>Time</th>
            <th>Status</th>
            <th>Class_Type</th>
            <th>Service</th>
            <th>Trainer_Name</th>
            <th>Rider_Name</th>
            <th>SubscriptionID</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) === 0): ?>
            <tr><td colspan="9" class="empty">No bookings found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo h($r['Booking_ID']); ?></td>
                <td><?php echo h($r['Date']); ?></td>
                <td><?php echo h(substr($r['Time'],0,5)); ?></td>
                <td><?php echo h($r['Status']); ?></td>
                <td><?php echo h($r['Class_Type']); ?></td>
                <td><?php echo h($r['Class_Type']) . (isset($r['Class_Number']) ? ' #' . (int)$r['Class_Number'] : ''); ?></td>
                <td><?php echo h($r['Trainer_Name']); ?></td>
                <td><?php echo h($r['Rider_Name']); ?></td>
                <td><?php echo h($r['Subscription_ID']); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
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

  // تشغيل الفلاتر تلقائيًا عند التغيير (بدون تغيير التصميم)
  const f = document.getElementById('filters');
  f.addEventListener('change', function(){ this.submit(); });
</script>
</body>
</html>
