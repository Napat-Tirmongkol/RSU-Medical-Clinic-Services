
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
$pid = $_POST['pid'];
$uid = $_POST['uid'];
$username = $_POST['username'];
$mobile = $_POST['mobile'];
//echo $lineid  . " " . $pid . " ". $username ;

$sql = "INSERT INTO welfareuser (lineid,pid,uid,username,mobile) VALUES ('$lineid','$pid','$uid','$username','$mobile')";
mysqli_query($conn, $sql);
?> 
<script>    alert('เชื่อมโยงข้อมูลผู้ใช้งาน เรียบร้อยแล้ว');   </script> 

<script>
var val = "<?php echo $lineid ?>";
url ="https://rsu.dodeep.co.th/fpdf/welfareuser.php?lineid="+val;
location.replace(url);
</script>

</body>
</html>
