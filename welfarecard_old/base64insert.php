
 <?php
include 'lib.php';
$pid    = $_POST['pid'];
$user   = $_POST['username'];
$birth  = $_POST['birth'];
$gender = $_POST['gender'];
$file   = $_FILES["fileToUpload"]["size"];

//echo $ . " " . $user . " " . $file ;

if( empty($pid) || empty($user) || empty($birth) || empty($gender) || $file == 0 )
{
	echo "<script>alert('กรุณากรอกข้อมูลให้ครบถ้วน !');</script>";
	echo "<script>location.replace('https://rsu.dodeep.co.th/fpdf/welfarecard.php?pid=". $pid ."');</script>";	
}
else
{
$pid  		= $_POST['pid'];
$signature 	= substr($_POST['signature'],5);
$sql = "SELECT * FROM welfarecard WHERE pid = '$pid' ";
$result = $conn->query($sql);
if ($result->num_rows == 0)
{
	$sql = "INSERT INTO welfarecard (pid,username,gender,birth,signature) VALUES ('$pid','$user','$gender','$birth','$signature')";
}
else
{	
	$sql = "UPDATE welfarecard SET username='$user',gender='$gender',birth='$birth',signature='$signature' WHERE pid = '$pid' " ;
}	
	if ($conn->query($sql) === TRUE) 
		echo "Update record created successfully";
	else  
		echo "Error: " . $sql . "<br>" . $conn->error;
	
	#---------------- Upload File 
	
	//$newfilename= date('dmYHis').str_replace(" ", "", basename($_FILES["file"]["name"]));

	$target_dir = "uploads/";
	$format = substr(basename($_FILES["fileToUpload"]["name"]),-4);
	$target_file = $target_dir . $pid . ".jpg" ;
	//$uploadOk = 1;
	$imageFileType = strtolower(pathinfo($target_file,PATHINFO_EXTENSION));
	if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)) 
	{
		echo "The file ". htmlspecialchars( basename( $_FILES["fileToUpload"]["name"])). " has been uploaded.";
	} 
	else 
	{
		echo "Sorry, there was an error uploading your file.";
	}

	header( "location: https://rsu.dodeep.co.th/fpdf/pdf.php?pid=". $pid );
	exit(0);
}
?>
