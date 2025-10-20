<?php
/***** 1) الجلسة *****/
session_start();
if (!isset($_SESSION['stable_id'])) { header('Location: stable_login.php'); exit; }
$STABLE_ID = (int) $_SESSION['stable_id'];

/***** 2) الاتصال *****/
ob_start();
require_once __DIR__ . '/dbcon.php'; // $conn
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
/***** 3) أدوات *****/
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fetch_all($conn, $sql, $types='', ...$params){
  $stmt = mysqli_prepare($conn, $sql);
  if ($types!=='') mysqli_stmt_bind_param($stmt, $types, ...$params);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $rows = [];
  if ($res) { while($r = mysqli_fetch_assoc($res)) $rows[] = $r; mysqli_free_result($res); }
  mysqli_stmt_close($stmt);
  return $rows;
}

/***** 4) فلاتر الواجهة *****/
$report_type  = $_GET['report'] ?? '';         // '' | 'orders' | 'loyal'
$filter_date  = trim($_GET['date'] ?? '');     // yyyy-mm-dd من input type=date
$filter_user  = trim($_GET['user'] ?? '');
$filter_status= trim($_GET['status'] ?? '');

$has_selection = in_array($report_type, ['orders','loyal'], true);

$rows = [];
$which_table = $has_selection ? $report_type : 'none';

/***** 5) تحميل البيانات حسب نوع التقرير *****/
if ($which_table === 'orders') {
  $sql = "
    SELECT
      b.Booking_ID        AS Report_ID,
      b.Class_Type        AS Type,
      b.Date              AS Creation_Date,
      st.Stable_name      AS Source,
      CONCAT(r.Fname,' ',r.Lname) AS Rider,
      s.Sub_name          AS Service,
      b.Status            AS Order_Status,
      s.Price             AS Price,
      CASE WHEN b.Status='paid' THEN s.Price ELSE 0 END AS Paid,
      CASE WHEN b.Status='paid' THEN 0 ELSE s.Price END AS Remaining,
      CASE 
        WHEN b.Status='paid' THEN 'Paid'
        WHEN b.Status='cancelled' THEN 'Cancelled'
        ELSE 'Unpaid'
      END AS Payment_Status
    FROM Booking b
    JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
    JOIN Rider r        ON r.Rider_ID        = b.Rider_ID
    JOIN Stable st      ON st.Stable_ID      = s.Stable_ID
    WHERE s.Stable_ID = ?
  ";
  $types = 'i';
  $params = [$STABLE_ID];

  if ($filter_date !== '') {
    $sql .= " AND b.Date = ? ";
    $types .= 's'; $params[] = $filter_date; // yyyy-mm-dd من input date
  }
  if ($filter_user !== '') {
    $sql .= " AND CONCAT(r.Fname,' ',r.Lname) LIKE ? ";
    $types .= 's'; $params[] = '%'.$filter_user.'%';
  }
  if ($filter_status !== '' && strtolower($filter_status) !== 'order status' && strtolower($filter_status) !== 'all') {
    $sql .= " AND b.Status = ? ";
    $types .= 's'; $params[] = $filter_status;
  }

  $sql .= " ORDER BY b.Booking_ID DESC ";
  $rows = fetch_all($conn, $sql, $types, ...$params);
}

if ($which_table === 'loyal') {
  $sql = "
    SELECT
      CONCAT(r.Fname,' ',r.Lname) AS Rider_Name,
      r.Phone,
      r.Age,
      r.Gender,
      SUM(CASE WHEN b.Status='paid' THEN s.Price ELSE 0 END) AS Total_Amount,
      COUNT(*) AS Total_Booking
    FROM Booking b
    JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
    JOIN Rider r        ON r.Rider_ID        = b.Rider_ID
    WHERE s.Stable_ID = ?
  ";
  $types = 'i';
  $params = [$STABLE_ID];

  if ($filter_date !== '') {
    $sql .= " AND b.Date = ? ";
    $types .= 's'; $params[] = $filter_date; // yyyy-mm-dd
  }
  if ($filter_user !== '') {
    $sql .= " AND CONCAT(r.Fname,' ',r.Lname) LIKE ? ";
    $types .= 's'; $params[] = '%'.$filter_user.'%';
  }
  if ($filter_status !== '' && strtolower($filter_status) !== 'order status' && strtolower($filter_status) !== 'all') {
    $sql .= " AND b.Status = ? ";
    $types .= 's'; $params[] = $filter_status;
  }

  $sql .= "
    GROUP BY r.Rider_ID
    ORDER BY Total_Booking DESC, Total_Amount DESC, Rider_Name ASC
  ";
  $rows = fetch_all($conn, $sql, $types, ...$params);
}
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
  <meta charset="UTF-8" />
  <title>Sahwa - Reports</title>
  <link rel="stylesheet" href="style1.css" />
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
    <a href="report2.php" class="active"><i class="fa fa-file-alt"></i> Report</a>
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
    <h2>Reports</h2>

    <!-- الفلاتر -->
    <form method="get" class="search-box" style="margin-bottom: 10px;">
      <select name="report">
        <option value=""        <?php echo ($report_type==''?'selected':''); ?>>Choose</option>
        <option value="loyal"   <?php echo ($report_type==='loyal'?'selected':''); ?>>The most loyal customer</option>
        <option value="orders"  <?php echo ($report_type==='orders'?'selected':''); ?>>Orders Report</option>
      </select>

      <!-- Date picker حقيقي -->
      <input type="date" name="date" value="<?php echo h($filter_date); ?>" />

      <input type="text" name="user" placeholder="user" value="<?php echo h($filter_user); ?>" />

      <select name="status">
        <option value="order status" <?php echo ($filter_status==='' || $filter_status==='order status' ? 'selected':''); ?>>order status</option>
        <option value="all"       <?php echo ($filter_status==='all'?'selected':''); ?>>All</option>
        <option value="paid"      <?php echo ($filter_status==='paid'?'selected':''); ?>>paid</option>
        <option value="pending"   <?php echo ($filter_status==='pending'?'selected':''); ?>>pending</option>
        <option value="cancelled" <?php echo ($filter_status==='cancelled'?'selected':''); ?>>cancelled</option>
      </select>

      <button type="submit" class="btn">View Report</button>
      <a href="report2.php" class="btn" style="margin-left:6px;">Reset</a>
    </form>

    <!-- لا نعرض أي جدول إذا ما تم اختيار نوع التقرير -->
    <?php if ($which_table === 'orders'): ?>
      <div class="table-box">
        <table>
          <thead>
            <tr>
              <th>Report ID</th>
              <th>Type</th>
              <th>Creation Date</th>
              <th>Source</th>
              <th>Rider</th>
              <th>Service</th>
              <th>Order Status</th>
              <th>Price</th>
              <th>Paid</th>
              <th>Remaining</th>
              <th>Payment Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($rows)===0): ?>
              <tr><td colspan="11" class="empty">No results for current filters.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td><?php echo h($r['Report_ID']); ?></td>
                <td><?php echo h($r['Type']); ?></td>
                <td><?php echo h($r['Creation_Date']); ?></td>
                <td><?php echo h($r['Source']); ?></td>
                <td><?php echo h($r['Rider']); ?></td>
                <td><?php echo h($r['Service']); ?></td>
                <td><?php echo h($r['Order_Status']); ?></td>
                <td><?php echo h($r['Price']); ?></td>
                <td><?php echo h($r['Paid']); ?></td>
                <td><?php echo h($r['Remaining']); ?></td>
                <td><?php echo h($r['Payment_Status']); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($which_table === 'loyal'): ?>
      <div class="table-box">
        <table>
          <thead>
            <tr>
              <th>Rider Name</th>
              <th>Phone</th>
              <th>age</th>
              <th>Gender</th>
              <th>Total Amount</th>
              <th>Total Booking</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($rows)===0): ?>
              <tr><td colspan="6" class="empty">No results for current filters.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td><?php echo h($r['Rider_Name']); ?></td>
                <td><?php echo h($r['Phone']); ?></td>
                <td><?php echo h($r['Age']); ?></td>
                <td><?php echo h($r['Gender']); ?></td>
                <td><?php echo h($r['Total_Amount']); ?></td>
                <td><?php echo h($r['Total_Booking']); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <!-- لا شيء: الصفحة بدون جداول إلى أن يختار المستخدم نوع التقرير -->
    <?php endif; ?>
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
</script>
</body>
</html>
