
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>..</title>
</head>
<body>

<?php
include 'lib.php';
$lineid = $_POST['lineid'];

$sql = "SELECT * FROM welfareuser WHERE lineid = '$lineid'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();

if( $row > 0)
{
$sql = "DELETE FROM welfareuser WHERE lineid = '$lineid'";
mysqli_query($conn, $sql);
?> <script>  alert('ยกเลิกการจัดเก็บข้อมูลเรียบร้อยแล้ว'); </script> <?php
}
?>

<script>
var val = "<?php echo $lineid ?>";
url ="https://rsu.dodeep.co.th/fpdf/welfareuser.php?lineid="+val;
location.replace(url);
</script>

</body>
</html>
