<?php

$uid = $_REQUEST['uid'];
$scan = scandir('./healthcheck/65/');

foreach($scan as $file) 
{
  if ($uid == substr($file,0,7)) 
  {  
      header("Location: https://rsu.dodeep.co.th/fpdf/healthcheck/65/".$file); 
      exit();
  }
}

?>
<script> 

alert("ขออภัยที่ไม่พบประวัติการตรวจสุขภาพของคุณ !");
history.back(); 

</script>
