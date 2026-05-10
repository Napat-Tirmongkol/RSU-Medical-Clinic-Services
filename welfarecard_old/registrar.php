
 <?php
include 'lib.php';
$pid    = $_REQUEST['pid'];
$registrar   = $_REQUEST['registrar'];

//echo $pid . " " . $registrar;


$sql = "UPDATE welfarecard SET registrar='$registrar',status='ดำเนินการเสร็จสิ้น'  WHERE pid = '$pid' " ;	
if ($conn->query($sql) === TRUE) 
	echo "Update record created successfully";
else  
	echo "Error: " . $sql . "<br>" . $conn->error;


header( "location: https://rsu.dodeep.co.th/fpdf/dashboard/index.php" );
exit(0);
?>
