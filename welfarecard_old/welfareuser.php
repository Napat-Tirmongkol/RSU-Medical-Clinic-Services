<?php
include 'lib.php';
$lineid = $_GET['lineid'];

if( empty($_GET['lineid']) )
{
?>
<script>
location.replace("https://rsu.dodeep.co.th/fpdf/login.php");
</script>
<?php } ?>

<html>
<head>
<title>ข้อมูลส่วนบุคคล</title>
<meta content="noindex, nofollow" name="robots">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" type="text/css" href="style.css">
<link rel="stylesheet" href="assets/css/flag-icon.min.css">
<link href="form.css" rel="stylesheet">
<style type="text/css">
  .dead_center {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    right: 0;
    width: 80%;
    height: 80%;
    margin: auto;
    background-color: white;
  }
</style>
</head>
<body>

<div id="main">
<center>
<div class="image-cropper"><img id="pictureUrl" width="20%"></div>
</center>
<p id="userId" hidden></p>
<p id="displayName" hidden></p>
<p id="getDecodedIDToken" hidden></p>

<?php
$con = 0 ;
$sql = "SELECT * FROM welfareuser WHERE lineid = '$lineid'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
if( $row > 0) {  $con = 1 ; $pid = $row['pid'];  $uid = $row['uid'];  $username = $row['username'];    $role = $row['role'];   };

if( $con == 1)
{
	echo "<br>";	
	echo "เลขบัตรประชาชน"." <input name='pid'  type='text' value=$pid readonly>";
    echo "<br>";		
	echo "ชื่อ-นามสกุล" ."<input name='username' type='text' value='$username' readonly>";
    echo "<br>";		
	echo "สิทธิสวัสดิการสุขภาพ" ."<input  type='text' value='ขั้นพื้นฐาน' readonly>";
    echo "<br>";	
	echo "<form action='healthcheck.php' method='post'>";
	echo "<input name='uid'  type='text' id='uid' value=$uid  hidden>";
	echo "<input name='dsubmit' type='submit' class='button button1' value='ผลตรวจสุขภาพ'>";
	echo "</form>";	
	
	$sql = "SELECT * FROM welfarecard WHERE pid = '$pid'";
	$result = $conn->query($sql);
	$row = $result->fetch_assoc();  
	if( $row > 0) 
	{	
   
		echo "สิทธิหลักประกันสุขภาพ" ."<input  type='text' value='$row[status]' readonly>";
		if( $row['status'] == 'รอดำเนินการ' ) 
		{	
	?>
	<form action="welfarecarddelete.php" method="post">
	<input name="lineid"  type="text" id="lineid" hidden>
	<input name="dsubmit" type="submit" class="button button2" value="แก้ไขการสมัคร" onclick="return confirm('Are you sure?')">
	</form>
	<?php 	
		}
		echo "<br><br>";	
    } 
	else 
	{	
		echo "ย้ายสิทธิหลักประกันสุขภาพ เหมาะกับผู้มีสิทธิอยู่ที่ต่างจังหวัด เพื่อความสะดวกในการใช้บริการด้านสุขภาพ <font style='color:red'><b>ฟรี</B></font>
		               ตรวจรักษาเฉพาะทาง จิตแพทย์ ทำฟัน ผ่าฟันคุด ขูด อุด ถอน  แพทย์แผนไทย กายภาพบำบัด ";
	    echo "<br><a href='https://eservices.nhso.go.th/eServices/mobile/login.xhtm'>[กรุณาตรวจสอบสิทธิการรักษา]<a>";
		echo "<br><br>";
		echo "<form action='welfarecard.php' method='post'>";
		echo "<input name='pid'  type='text' id='pid' value=$pid hidden>";
		echo "<input type='submit' class='button button2' value='สมัครสิทธิหลักประกันสุขภาพ'>";
		echo "</form>";
    }
?>     
	<form action="welfaredelete.php" method="post">
	<input name="lineid"  type="text" id="lineid" hidden>
	<input name="dsubmit" type="submit" class="button button3" value="ยกเลิกการเชื่อมโยง" onclick="return confirm('Are you sure?')">
	</form>
<?php  

	
//echo "<center><div class='image-cropper'>";	
//echo "<img src='https://chart.googleapis.com/chart?chs=400x400&cht=qr&chl=https://rsu.dodeep.co.th/lineoa/getadvisor.php?pid=$pid'>";
//echo "</div></center>";
	
  
}
else
{
  echo "<form action='welfareconnect.php' method='post'>";
  echo "<input name='lineid'  type='text' id='lineid' hidden >";
  echo "<br>";	
  echo "เลขบัตรประชาชน" ."<input name='pid'  type='text' pattern='[0-9]+'  minlength='13' maxlength='13' required>";
  echo "<br>";	 
  echo "รหัสนักศึกษา/บุคลากร" ."<input name='uid' pattern='[0-9]+' required>";
  echo "<br>";	
  echo "ชื่อ-นามสกุล" . "<input name='username'  type='text' required >"; 
  echo "<br>";	 
  echo "หมายเลขโทรศัพท์" . "<input name='mobile'  type='text' pattern='[0-9]+' required >"; 
  echo "<br><br>";	    
  echo "<input name='dsubmit' type='submit' value='เชื่อมโยงบัญชี'>";
  echo "</form>";
  echo "<table style='width:260px'><tr><td>
                   การเชื่อมโยงบัญชี เป็นการยอมรับในการนำเอาข้อมูลไปใช้ในระบบบริการเพื่อสุขภาพ 
                   เช่น การดูผลตรวจสุขภาพ การเข้ารับบริการหรือขอรับคำปรึกษาด้านสุขภาพ ทั้งนี้ผู้ที่เป็นเจ้าของข้อมูลสามารถทำการยกเลิกการเชื่อมโยงบัญชีได้ในอนาคต 
		</td></tr></tabel>";
}
?>


</div>

</body>
</html>

<script src="https://static.line-scdn.net/liff/edge/2.1/sdk.js"></script>

<script>
  function runApp() {
    liff.getProfile().then(profile => {
      document.getElementById("pictureUrl").src = profile.pictureUrl;
      document.getElementById("userId").innerHTML = profile.userId;
	  document.getElementById("lineid").value = profile.userId;
      document.getElementById("displayName").innerHTML =  profile.displayName;
      document.getElementById("getDecodedIDToken").innerHTML = liff.getDecodedIDToken().email;
    }).catch(err => console.error(err));
  }
  liff.init({ liffId: "1657627999-bpxPZazB" }, () => {
    if (liff.isLoggedIn()){   runApp();   }
    else {        liff.login();      }
  }, err => console.error(err.code, error.message));
</script>
