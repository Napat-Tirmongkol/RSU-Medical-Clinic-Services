<!doctype html>

<html>
<head>
    <title>สมัครสิทธิหลักประกันสุขภาพ</title>
	<meta content="noindex, nofollow" name="robots">
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="stylesheet" type="text/css" href="style.css">
	<link rel="stylesheet" href="assets/css/flag-icon.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/themify-icons.css">
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
		#signature{
			width: 280px; height: 80px;
			border: 1px solid black;
		}
		td {
		  text-align: left;
		}	
	</style>
</head>
    
<body>
<div id="main">
<br> 
<center>
<div class="image-cropper"><img id="pictureUrl"></div>
<h4>สมัครสิทธิหลักประกันสุขภาพ </h4>
</center>		
<?php
include 'lib.php';
$pid = $_REQUEST['pid'];
$sql = "SELECT * FROM welfareuser WHERE pid = '$pid'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
if( $row > 0) 
{ 
	$pid = $row['pid'];  
	$username = $row['username']; 
}
?>
		<form action='base64insert.php' name='editdata' method="post" id="info" enctype="multipart/form-data">	
			รหัสบัตรประชาชน
			<input type="text" name="pid" id="pid" readonly>			
			ชื่อ-นามสกุล
			<input type="text" name="username" id="username" readonly>	
			<table> 
			<tr>
				<td>
				วันเดือนปีเกิด
				<input type="date" name="birth" id="birth" >		
				</td>
				<td>			
				เพศ
				<select name="gender" id="gender" >	
				<option ></option>
				<option value='ชาย'>ชาย</option>
				<option value='หญิง'>หญิง</option>
				</select>
				</td>
			</tr>
			<table>
			
			อัพโหลดรูปถ่ายคู่กับบัตรประชาชน 
			<input type="file" id="fileToUpload" name="fileToUpload">
			
			<img src='./images/youcard.png' style="width:280px">

			<br>	
			<font color='red'>กรุณาลงลายมือชื่อในกรอบสี่เหลี่ยม </font><!-- Signature -->
			<div id="signature" style=''>
				<canvas id="signature-pad" class="signature-pad" width="280px" height="80px"></canvas>
			</div>
			
			<table>
			<tr>
			<td>
			<input type='button' id='clear' class="button button4" value='ล้างลายมือชื่อ' onclick='clearpad()'>
			</td>			
			<td>
			<input type='button' id='click' class="button button5" value='ยืนยัน' >
			</td>
			</tr>
			</table>
			<textarea  name='signature' id='output' hidden></textarea><br/>
			
        </form>
		
		<script>
		document.getElementById("pid").value ="<?php echo $pid ?>";		
		document.getElementById("username").value ="<?php echo $username ?>" ;	
		</script>
        <!-- Preview image -->
        <!--img src='' id='sign_prev' style='display: none;' /-->

        <!-- Script -->
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
        <script src="signature_pad.js"></script>
    
        <script>
		
		$(document).ready(function() {
			var signaturePad = new SignaturePad(document.getElementById('signature-pad'));
			$('#click').click(function(){
				var data = signaturePad.toDataURL('image/png');
				$('#output').val(data);
				//$("#sign_prev").show();
				//$("#sign_prev").attr("src",data);
				// Send data to server instead...
				//window.open(data);		
                document.getElementById("info").submit();
			});
		})
		
		function clearpad()
		{
			var canvas=document.getElementById("signature-pad");
			var context=canvas.getContext("2d");
			context.clearRect(0,0,canvas.width,canvas.height);
		}		
		
		
        </script>
</div>
</body>
</html>

<script src="https://static.line-scdn.net/liff/edge/2.1/sdk.js"></script>

<script>
  function runApp() {
    liff.getProfile().then(profile => {
      document.getElementById("pictureUrl").src = profile.pictureUrl;
      document.getElementById("userId").innerHTML = profile.userId;
      document.getElementById("displayName").innerHTML =  profile.displayName;
      document.getElementById("getDecodedIDToken").innerHTML = liff.getDecodedIDToken().email;
    }).catch(err => console.error(err));
  }
  liff.init({ liffId: "1657627999-bpxPZazB" }, () => {
    if (liff.isLoggedIn()){   runApp();   }
    else {        liff.login();      }
  }, err => console.error(err.code, error.message));
</script>
