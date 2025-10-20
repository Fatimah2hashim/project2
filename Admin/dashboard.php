<?php
/***** 1) جلسة وتحقق *****/
session_start();
if (!isset($_SESSION['stable_id'])) { header('Location: stable_login.php'); exit; }
$STABLE_ID = (int) $_SESSION['stable_id'];

/***** 2) ربط قاعدة البيانات عبر dbcon.php مع كتم أي طباعة *****/
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

/***** 4) أرقام الداشبورد *****/
// Total Sales
$row_sales = fetch_one($conn,"
  SELECT COALESCE(SUM(p.Amount),0) AS total_sales
  FROM Payment p
  JOIN Booking b ON b.Booking_ID = p.Booking_ID
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  WHERE s.Stable_ID = ? AND p.Payment_Status = 'Succeeded'
",'i',$STABLE_ID);

// Customers
$row_customers = fetch_one($conn,"
  SELECT COUNT(DISTINCT b.Rider_ID) AS total_customers
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  WHERE s.Stable_ID = ?
",'i',$STABLE_ID);

// Orders
$row_orders = fetch_one($conn,"
  SELECT COUNT(*) AS total_orders
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  WHERE s.Stable_ID = ?
",'i',$STABLE_ID);

// Reservations (Pending)
$row_pending = fetch_one($conn,"
  SELECT COUNT(*) AS total_reservations
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  WHERE s.Stable_ID = ? AND b.Status='pending'
",'i',$STABLE_ID);

$dashboard_stats = [
  'total_sales'        => number_format((float)($row_sales['total_sales'] ?? 0), 2),
  'total_customers'    => (int)($row_customers['total_customers'] ?? 0),
  'total_orders'       => (int)($row_orders['total_orders'] ?? 0),
  'total_reservations' => (int)($row_pending['total_reservations'] ?? 0),
];

/***** 5) Today Orders = أحدث 10 حجوزات (الأحدث أولاً) *****/
$today_rows = fetch_all($conn,"
  SELECT b.Booking_ID, b.Status, b.Date, b.Time, b.Class_Type, b.Class_Number,
         CONCAT(r.Fname,' ',r.Lname) AS Rider_Name
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  JOIN Rider r ON r.Rider_ID = b.Rider_ID
  WHERE s.Stable_ID = ?
  ORDER BY b.Booking_ID DESC
  LIMIT 10
",'i',$STABLE_ID);

/***** 6) بيانات الباي تشارت (Top 5 مرتبة تنازليًا) *****/
// Most Demanded Services
$services = fetch_all($conn,"
  SELECT b.Class_Type AS label, COUNT(*) AS cnt
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  WHERE s.Stable_ID = ?
  GROUP BY b.Class_Type
  ORDER BY cnt DESC
  LIMIT 5
",'i',$STABLE_ID);

// Trainer Workload
$workload = fetch_all($conn,"
  SELECT CONCAT(t.Fname,' ',t.Lname) AS label, COUNT(*) AS cnt
  FROM Booking b
  JOIN Subscription s ON s.Subscription_ID = b.Subscription_ID
  JOIN Trainer t ON t.Trainer_ID = b.Trainer_ID
  WHERE s.Stable_ID = ?
  GROUP BY t.Trainer_ID
  ORDER BY cnt DESC
  LIMIT 5
",'i',$STABLE_ID);

$services_json = json_encode($services, JSON_UNESCAPED_UNICODE);
$workload_json = json_encode($workload, JSON_UNESCAPED_UNICODE);


?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>StrideHub Dashboard</title>
  <link rel="stylesheet" href="style1.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* ألغِ أي خلفية للدوائر مهما كان المصدر */
   .pie, .pie.services, .pie.sources { background: none !important; }

/* خلّي الكانفس يغطي الدائرة تمامًا */
   .pie > canvas { width: 100% !important; height: 100% !important; display: block; border-radius: 50%; }
 
  </style>


</head>
<body>

  <!-- الشريط الجانبي -->
  <div class="sidebar" id="sidebar">
    <a href="dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
    <a href="booking.php"><i class="fa fa-calendar"></i> Booking</a>
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
     <h1 class="logo" >Sahwa</h1>
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
      <div class="profile"><img src="<?php echo htmlspecialchars($__profile_src__, ENT_QUOTES, 'UTF-8'); ?>"  alt="Profile" width="80" height="80"></div>
    </div>
  </div>

  <!-- المحتوى -->
  <div class="main">
    <!-- الكروت -->
    <div class="cards">
    
    <!-- 1. Total Sales -->
    <div class="card sales">
        <h3>SAR <?php echo $dashboard_stats['total_sales']; ?></h3> 
        <p>Total Sales</p>
        <a href="report2.php">View All</a>
    </div>
    
    <!-- 2. Customers -->
    <div class="card customers">
        <h3><?php echo $dashboard_stats['total_customers']; ?></h3> 
        <p>Customers</p>
        <a href="rider1.php">View All</a>
    </div>
    
    <!-- 3. Orders -->
    <div class="card orders">
        <h3><?php echo $dashboard_stats['total_orders']; ?></h3>
        <p>Orders</p>
        <a href="booking.php">View All</a>
    </div>
    
    <!-- 4. Reservations -->
    <div class="card reservations">
        <h3><?php echo $dashboard_stats['total_reservations']; ?></h3> 
        <p>Reservations (Pending)</p>
        <a href="res.php">View All</a>
    </div>
    
</div>

    <!-- الرسومات -->
    <div class="charts">
      <div class="chart">
        <div class="pie services"></div>
        <h3>Most Demanded Services</h3>
        <div class="legend" id="legend-services"></div>
      </div>
      <div class="chart">
        <div class="pie sources"></div>
        <h3>Trainer Workload</h3>
        <div class="legend" id="legend-workload"></div>
      </div>
    </div>

    <!-- الجداول -->
    <div class="tables">
      <div class="table-box">
        <h3>Today Orders</h3>
        <table>
          <tr><th>OID</th><th>Status</th><th>Date</th><th>Class</th><th>Rider Name</th></tr>
          <?php if (count($today_rows) === 0): ?>
            <tr><td>-</td><td>-</td><td>-</td><td>-</td><td>-</td></tr>
          <?php else: ?>
            <?php foreach ($today_rows as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['Booking_ID']); ?></td>
                <td><?php echo htmlspecialchars($r['Status']); ?></td>
                <td><?php echo htmlspecialchars($r['Date'] . ' ' . substr($r['Time'],0,5)); ?></td>
                <td><?php echo htmlspecialchars($r['Class_Type']) . (isset($r['Class_Number']) ? ' #' . (int)$r['Class_Number'] : ''); ?></td>
                <td><?php echo htmlspecialchars($r['Rider_Name']); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </table>
      </div>
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

  // ====== بيانات الباي تشارت من PHP ======
  const servicesData = <?php echo $services_json ?: '[]'; ?>;   // [{label, cnt}]
  const workloadData = <?php echo $workload_json ?: '[]'; ?>;   // [{label, cnt}]

  // ألوان ثابتة ومتناسقة (5 عناصر)
  const PIE_COLORS = ['#8b2e1d','#1c2a39','#d6b49b','#a6462e','#325d7f'];

  function drawPie(divSelector, dataArray, colors) {
    const container = document.querySelector(divSelector);
    if (!container) return;
    const size = Math.min(container.clientWidth || 260, 260);
    const c = document.createElement('canvas');
    c.width = size; c.height = size;
    container.innerHTML = '';
    container.appendChild(c);
    const ctx = c.getContext('2d');

    const total = dataArray.reduce((s, x) => s + Number(x.cnt), 0) || 1;
    let start = -Math.PI / 2;

    dataArray.forEach((item, i) => {
      const val = Number(item.cnt);
      const angle = (val / total) * Math.PI * 2;
      ctx.beginPath();
      ctx.moveTo(size/2, size/2);
      ctx.arc(size/2, size/2, size/2 - 2, start, start + angle);
      ctx.closePath();
      ctx.fillStyle = colors[i % colors.length];
      ctx.fill();
      start += angle;
    });
  }

  function buildLegend(legendId, dataArray, colors) {
    const legend = document.getElementById(legendId);
    if (!legend) return;
    legend.innerHTML = '';
    const total = dataArray.reduce((s, x) => s + Number(x.cnt), 0) || 1;

    dataArray.forEach((item,i)=>{
      const row = document.createElement('div');
      const dot = document.createElement('span');
      dot.style.background = colors[i % colors.length];
      dot.style.display = 'inline-block';
      dot.style.width = '12px';
      dot.style.height = '12px';
      dot.style.marginRight = '8px';
      dot.style.borderRadius = '3px';
      const pct = ((Number(item.cnt)/total)*100).toFixed(0);
      row.appendChild(dot);
      row.appendChild(document.createTextNode(` ${item.label} — ${item.cnt} (${pct}%)`));
      legend.appendChild(row);
    });
  }

  // رسم التشارتين (Top 5 مرتّبة)
  drawPie('.pie.services', servicesData, PIE_COLORS);
  buildLegend('legend-services', servicesData, PIE_COLORS);

  drawPie('.pie.sources', workloadData, PIE_COLORS);
  buildLegend('legend-workload', workloadData, PIE_COLORS);
</script>
</body>
</html>
