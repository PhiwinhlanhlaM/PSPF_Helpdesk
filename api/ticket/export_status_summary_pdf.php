<?php
require './vendor/autoload.php';
use Dompdf\Dompdf;

// Session and DB
session_start();
$mysqli = new mysqli("localhost", "root", "", "pspf_helpdesk");
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Fetch filters
$assigned_to = $_GET['assigned_to'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build WHERE clause
$where = [];
if ($assigned_to) $where[] = "assigned_to = '" . $mysqli->real_escape_string($assigned_to) . "'";
if ($start_date) $where[] = "query_date >= '" . $mysqli->real_escape_string($start_date) . "'";
if ($end_date) $where[] = "query_date <= '" . $mysqli->real_escape_string($end_date) . "'";

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get ticket status summary
$sql = "SELECT status, COUNT(*) AS cnt FROM tickets $whereClause GROUP BY status";
$result = $mysqli->query($sql);

$labels = ['Open', 'In Progress', 'Closed', 'Escalated'];
$data = array_fill_keys($labels, 0);

while ($row = $result->fetch_assoc()) {
    if (in_array($row['status'], $labels)) {
        $data[$row['status']] = (int)$row['cnt'];
    }
}

// Start buffering HTML
ob_start();
?>

<!DOCTYPE html>
<html>
<head>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h2 { text-align: center; color: #406997; }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 30px;
    }
    th, td {
      border: 1px solid #406997;
      padding: 10px;
      font-size: 14px;
      text-align: center;
    }
    th {
      background-color: #406997;
      color: white;
    }
  </style>
</head>
<body>

<h2>Ticket Status Summary Report</h2>

<?php if ($assigned_to || $start_date || $end_date): ?>
  <p><strong>Filtered By:</strong><br>
    <?php if ($assigned_to) echo "Agent: $assigned_to<br>"; ?>
    <?php if ($start_date) echo "From: $start_date<br>"; ?>
    <?php if ($end_date) echo "To: $end_date<br>"; ?>
  </p>
<?php endif; ?>

<table>
  <thead>
    <tr>
      <?php foreach ($labels as $label): ?>
        <th><?= strtoupper($label) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <tr>
      <?php foreach ($labels as $label): ?>
        <td><?= $data[$label] ?></td>
      <?php endforeach; ?>
    </tr>
  </tbody>
</table>

</body>
</html>

<?php
$html = ob_get_clean();

// Generate PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("Ticket_Status_Summary.pdf", ["Attachment" => false]);

$mysqli->close();
exit;
?>
