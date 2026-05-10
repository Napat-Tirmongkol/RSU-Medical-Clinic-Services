<?php
include '../lib.php';

if( isset($_COOKIE['lineid'])  ) $lineid = $_COOKIE['lineid'] ;

$sql = "SELECT * FROM welfareuser WHERE lineid = '$lineid' ";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$registrarid = $row['uid'];
$registrar =  $row['username'];

if( $row['role'] == "ผู้ใช้งาน" ) 
{
header( "location: https://rsu.dodeep.co.th/fpdf/dashboard/index.html" );
exit(0);
}

?>

<!doctype html>
<!--[if lt IE 7]>      <html class="no-js lt-ie9 lt-ie8 lt-ie7" lang=""> <![endif]-->
<!--[if IE 7]>         <html class="no-js lt-ie9 lt-ie8" lang=""> <![endif]-->
<!--[if IE 8]>         <html class="no-js lt-ie9" lang=""> <![endif]-->
<!--[if gt IE 8]><!--> <html class="no-js" lang=""> <!--<![endif]-->
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>สำนักงานสวัสดิการสุขภาพ</title>
    <meta name="description" content="Sufee Admin - HTML5 Admin Template">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="apple-touch-icon" href="apple-icon.png">
    <link rel="shortcut icon" href="logo.ico">

    <link rel="stylesheet" href="assets/css/normalize.css">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/themify-icons.css">
    <link rel="stylesheet" href="assets/css/flag-icon.min.css">
    <link rel="stylesheet" href="assets/css/cs-skin-elastic.css">
    <link rel="stylesheet" href="assets/css/lib/datatable/dataTables.bootstrap.min.css">
    <!-- <link rel="stylesheet" href="assets/css/bootstrap-select.less"> -->
    <link rel="stylesheet" href="assets/scss/style.css">
    <link href='https://fonts.googleapis.com/css?family=Open+Sans:400,600,700,800' rel='stylesheet' type='text/css'>
    <!-- <script type="text/javascript" src="https://cdn.jsdelivr.net/html5shiv/3.7.3/html5shiv.min.js"></script> -->
    <link rel="stylesheet" type="text/css" href="style.css">

    <link rel="stylesheet" href="https://cdn.datatables.net/colreorder/1.5.1/css/colReorder.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.19/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/1.5.6/css/buttons.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.3.1.js"></script>
    <script src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/colreorder/1.5.1/js/dataTables.colReorder.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.5.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.5.6/js/buttons.colVis.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.6.2/js/buttons.html5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>

    <script src="assets/js/vendor/jquery-2.1.4.min.js"></script>
    <script src="assets/js/popper.min.js"></script>
    <script src="assets/js/plugins.js"></script>
    <script src="assets/js/main.js"></script>
    <script src="assets/js/lib/data-table/datatables.min.js"></script>
    <script src="assets/js/lib/data-table/dataTables.bootstrap.min.js"></script>
    <script src="assets/js/lib/data-table/dataTables.buttons.min.js"></script>
    <script src="assets/js/lib/data-table/buttons.bootstrap.min.js"></script>
    <script src="assets/js/lib/data-table/jszip.min.js"></script>
    <script src="assets/js/lib/data-table/pdfmake.min.js"></script>
    <script src="assets/js/lib/data-table/vfs_fonts.js"></script>
    <script src="assets/js/lib/data-table/buttons.html5.min.js"></script>
    <script src="assets/js/lib/data-table/buttons.print.min.js"></script>
    <script src="assets/js/lib/data-table/buttons.colVis.min.js"></script>
    <script src="assets/js/lib/data-table/datatables-init.js"></script>






</head>
<body>
  <!-- Left Panel -->

  <aside id="left-panel" class="left-panel">
  <nav class="navbar navbar-expand-sm navbar-default">

  <div class="navbar-header">
  <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#main-menu" aria-controls="main-menu" aria-expanded="false" aria-label="Toggle navigation">
  <i class="fa fa-bars"></i>
  </button>
  </div>
  <center>
  <p id="userId" hidden></p>
  <p id="displayName" hidden></p>
  <p id="getDecodedIDToken" hidden></p>
  </center>
  <br>
  <!-- ==================================================================== !-->
  <div id="main-menu" class="main-menu collapse navbar-collapse">
  <ul class="nav navbar-nav">
  <li class="active">
      <a href="https://rsu.dodeep.co.th/fpdf/dashboard/index.php"> <i class="menu-icon fa fa-dashboard"></i>Dashboard </a>
  </li>
  <h3 class="menu-title">Health - Information</h3><!-- /.menu-title -->
  <!-- ==================================================================== !-->
	<li>
	<a href="requestlist.php"> <i class="menu-icon fa fa-table"></i>แบบคำขอโอนย้ายสิทธิ</a>
	</li>


	<li>
	<a href="index.html" onclick="liff.logout()"> <i class="menu-icon fa fa-sign-out"></i>ออกจากระบบ</a>
	</li>
  <!-- ==================================================================== !-->

  </ul>
  </div><!-- /.navbar-collapse -->
  </nav>
  </aside><!-- /#left-panel -->

  <!-- Left Panel -->

    <!-- Right Panel -->

    <div id="right-panel" class="right-panel">

        <!-- Header-->
        <header id="header" class="header">

            <div class="header-menu">

              <div class="col-sm-7">
                  <a id="menuToggle" class="menutoggle pull-left"><i class="fa fa fa-tasks"></i></a>
                  <p style="font-size:22px">
                    <img src="images/logo.png" width="10%">
						สำนักงานสวัสดิการสุขภาพ มหาวิทยาลัยรังสิต
                  </p>
			  
				  
              </div>

                <div class="col-sm-5">
                    <div class="user-area dropdown float-right">
                        <a href="#" class="dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <img class="user-avatar rounded-circle" id="pictureUrl" alt="User Avatar">
                        </a>
                    </div>
                </div>

            </div>

        </header>
<!-- /header -->
        <!-- Header-->

	<div class="breadcrumbs">
		<div class="col-sm-4">
			<div class="page-header float-left">
			<div class="page-title">
				<div>
				<b> การโอนย้ายสิทธิเข้ารักษาที่ มหาวิทยาลัยรังสิต </b>	 
				<!--button type="button" name="add" id="add" data-toggle="modal" data-target="#add_data_Modal" class="btn btn-warning">เพิ่มข้อมูล</button-->
				</div>							
			</div>
			</div>
		</div>
	</div>




        <div class="content mt-3">
            <div class="animated fadeIn">
                <div class="row">

                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <strong class="card-title">Data Table</strong>
                        </div>
                        <div class="card-body">
						


<!-- Data Table -->

<table id="bootstrap-data-table" class="table table-striped table-bordered">
<thead>
  <tr  align= "center">
    <th style='font-size:10px' width='12%'>บัตรประชาชน</th>
    <th style='font-size:12px' width='25%'>ชื่อ-นามสกุล</th>
    <th style='font-size:12px' width='10%'>เบอร์โทร</th>
    <!--th style='font-size:12px' width='15%'>วันเดือนปีเกิด</th-->
    <th style='font-size:12px' >วันลงทะเบียน</th>	
	<th style='font-size:12px' width='6%'>สถานะ</th>	
	<th style='font-size:12px' >ข้อความ</th>
	<th style='font-size:12px' >รูป</th>
	<th style='font-size:12px' >เอกสาร</th>
	<th style='font-size:12px' >แก้ไข</th>
	<th style='font-size:12px' >พิจารณา</th>	
  </tr>
</thead>
<tbody>


<?php
$today = date('d/m/Y');
$sql = "SELECT * FROM welfarecard , welfareuser WHERE welfarecard.pid = welfareuser.pid   ORDER BY submitdate DESC";

$result = $conn->query($sql);
while($row = $result->fetch_assoc())
{
?>
                      <tr>
                        <td style='font-size:12px' align= "center"><?php echo $row['pid'] ?></td>						
                        <td style='font-size:12px'><?php echo $row['username'] ?></td>						
                        <td style='font-size:12px'><?php echo  $row['mobile']  ?></td>						
                        <!--td style='font-size:12px' align= "center"><?php //echo DateThai($row['birth']) ?></td-->						
                        <td style='font-size:12px' align= "center"><?php echo $row['submitdate'] ?></td>                       
                        <td style='font-size:12px' align= "center">
						
						<?php 
						if ( $row['status'] == "รอดำเนินการ"  || $row['status'] == "รอส่งเอกสาร"  ||  $row['status'] == "รอตัดสินใจ" )											
							echo "<font color='orange'> $row[status] </font>";
						if ($row['status'] == "อนุมัติ" )		
							echo "<font color='green'>$row[status]</font>";						
						if ($row['status'] == "ไม่อนุมัติ" )		
							echo "<font color='red'> $row[status] </font>";							
						?>						
									
						</td> 	
						
                        <td align='center'>						
                        <img src="./images/message.png" style="width:20px" name="sms" id="sms" data-toggle="modal" data-target="#sms_data_Modal"
                        onClick="setSMS('<?php echo $row['pid'] ?>','<?php echo $row['username'] ?>')" title="ส่งข้อความ">				
                        </td>		
						
						<td align='center'>
						<a href="https://rsu.dodeep.co.th/fpdf/watermark.php?image=<?php echo $row['pid'] ?>.jpg"  target="_blank"> 
						<img src="./images/idcard.png" style="width:25px">
						</a>
						</td>
                        <td align='center'>					
						<a href="https://rsu.dodeep.co.th/fpdf/pdf.php?pid=<?php echo $row['pid'] ?>" target="_blank"> 
						<img src="./images/agreement.png" style="width:20px" title="แบบยื่นขอย้ายสิทธิ">
						</a>
						</td>												
                        <td align='center'>						
                        <img src="./images/editicon.png" style="width:20px" name="edit" id="edit" data-toggle="modal" data-target="#edit_data_Modal"
                        onClick="setEdit(
                        '<?php echo $row['pid'] ?>','<?php echo $row['username'] ?>','<?php echo $row['gender'] ?>','<?php echo $row['birth'] ?>','<?php echo $row['status'] ?>'
                        )" title="แก้ไขข้อมูล">				
                        </td>						
						<td align='center'>
                        <img src="./images/signature.png" style="width:20px" name="app" id="app" data-toggle="modal" data-target="#app_data_Modal"
                        onClick="setAPP('<?php echo $row['pid'] ?>','<?php echo $row['username'] ?>','<?php echo $registrarid ?>','<?php echo $registrar ?>' )" title="ดำเนินการ">				
						
						<!--a href="https://rsu.dodeep.co.th/fpdf/registrar.php?pid=<?php echo $row['pid'] ?>&registrar=<?php echo $registrar ?>"> 
						<img src="./images/signature.png" style="width:20px" onclick="return confirm('Are you sure?')" title="ลงลายมือชื่อ">
						</a-->
						</td>						
                      </tr>
<?php } ?>


                    </tbody>
</table>

<!-- .animated -->
                        </div>
                    </div>
                </div>


                </div>
            </div><!-- .animated -->
        </div><!-- .content -->


    </div><!-- /#right-panel -->

    <!-- Right Panel -->




    <script>

       $(document).ready(function() {
        // Setup - add a text input to each footer cell

        // DataTable
            var table = $('#bootstrap-data-table').DataTable( {
			order: [[3, 'desc']],
            dom: 'Bfrtip',
            pageLength: 20,
            colReorder: true,
            buttons: [ 'csv','excel' ]
        } );

    } );

    </script>

    <script src="https://static.line-scdn.net/liff/edge/2.1/sdk.js"></script>
    <script>
	var userId ;
      function runApp() {
        liff.getProfile().then(profile => {
          document.getElementById("pictureUrl").src = profile.pictureUrl;
          document.getElementById("userId").innerHTML = profile.userId;
          document.getElementById("displayName").innerHTML =  profile.displayName;
          document.getElementById("getDecodedIDToken").innerHTML = liff.getDecodedIDToken().email	  
		  document.cookie =  'lineid='+profile.userId;	
		  
        }).catch(err => console.error(err));
      }
      liff.init({ liffId: "1657627999-eOJ9j2nO" }, () => {
        if (liff.isLoggedIn()){   runApp();   }
        else {        liff.login();      }
      }, err => console.error(err.code, error.message));
    </script>

</body>
</html>
<!----------------------------------------------------------------------------------------------------->
<div id="edit_data_Modal" class="modal fade">
     <div class="modal-dialog modal-lg" style="width:80%;">
          <div class="modal-content">
               <div class="modal-header">
                    <h4 class="modal-title"><img src=./images/editicon.png width=5%> แก้ไขข้อมูล</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
               </div>
               <div class="modal-body">
                    <form action='edit.php' name='editdata' method="get" id="edit_form">
					
						<input type="text" name="editor" id="editor" value=<?php echo $registrarid ?> hidden>
					
                      <div class="row align-items-center">
                          <div class="col-3"><label>บัตรประชาชน </label> <input type="text" name="pid" id="pid" class="form-control" ></div>
                          <div class="col"><label>ชื่อ-นามสกุล</label> <input type="text" name="username" id="username" class="form-control" ></div>
                        <div class="col-2"><label>เพศ</label><input type="text" name="gender" id="gender" class="form-control" ></div>
					  </div>
                     </br>
                      <div class="row g-2 align-items-center">
           			   <div class="col-3"><label>วันเดือนปีเกิด</label><input type="date" name="birth" id="birth" class="form-control" ></div>		           					  					  
 					   <!--div class="col-6"><label>สถานะการโอนย้าย</label> 
                         <select name="status" id="status" class="form-control">
						 <option value =''> </option>
						 <option value ='รอดำเนินการ'> รอดำเนินการ</option>
						 <option value ='อนุมัติ'> อนุมัติ</option>	
						 <option value ='ไม่อนุมัติ'> ไม่อนุมัติ</option>						 
						 </select>
						</div-->                     
					  </div>
					  </br>
                      <!--div class="row align-items-center">
                        <div class="col-6"><label>เอกสารการโอนย้ายสิทธิ  </label> 
						<input type="file" id="fileToUpload" name="fileToUpload" class="form-control" >						  
						</div>
                      </div-->					  
                      </br>
                         <input type="submit" name="edit" id="edit" value="บันทึก" class="btn btn-success" />
                         <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                    </form>
               </div>
          </div>
     </div>
</div>
<!----------------------------------------------------------------------------------------------------->
<div id="sms_data_Modal" class="modal fade">
    <div class="modal-dialog modal-lg" style="width:80%;">
        <div class="modal-content">
               <div class="modal-header">
                    <h4 class="modal-title"><img src="./images/message.png" style="width:10%;"> ส่งข้อความ</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
               </div>
               <div class="modal-body">
                    <form action='message.php' name='smsdata' method="get" id="sms_form">
					  <input type="text" name="sender" id="sender" value=<?php echo $registrarid ?> hidden>
                      <div class="row align-items-center">
                          <div class="col-3"><label>บัตรประชาชน </label> <input type="text" name="uid" id="uid" class="form-control" readonly></div>
                          <div class="col"><label>ชื่อ-นามสกุล</label> <input type="text" name="receiver" id="receiver" class="form-control" readonly></div>
					  </div>
					  <br>				  					  
                      <div class="row g-2 align-items-center">
                          <div class="col"><label>ข้อความ</label> <input type="text" name="message" id="message" class="form-control" ></div>
					  </div>            
					  <br><br>					  
                      <input type="submit" name="sms" id="sms" value="ยืนยัน" class="btn btn-success" />
                      <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                    </form>
				</div>		
		</div> 					
    </div>
</div>
<!----------------------------------------------------------------------------------------------------->
<div id="app_data_Modal" class="modal fade">
    <div class="modal-dialog modal-lg" style="width:80%;">
        <div class="modal-content">
               <div class="modal-header">
                    <h4 class="modal-title"><img src="./images/signature.png" style="width:10%;"> การโอนย้ายสิทธิ</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
               </div>
               <div class="modal-body">
                    <form action='approve.php' name='appdata' method="get" id="app_form">
                      <div class="row align-items-center">
                          <div class="col-3"><label>บัตรประชาชน </label> <input type="text" name="appid" id="appid" class="form-control" readonly></div>
                          <div class="col"><label>ชื่อ-นามสกุล</label> <input type="text" name="appusername" id="appusername" class="form-control" readonly></div>
					  </div>
					  <br>				  					  
                      <div class="row g-2 align-items-center">
                          <div class="col-3"><label>เจ้าหน้าที่</label><input type="text" name="registrarid" id="registrarid" class="form-control" readonly></div>
                          <div class="col"><label>ชื่อ-นามสกุล</label><input type="text" name="registrar" id="registrar" class="form-control" readonly></div>
						  <div class="col-3"><label>ผลการพิจารณา</label>
						  <select name="approve" id="approve" class="form-control" >
						  <option value="รอดำเนินการ">รอดำเนินการ</option>
						  <option value="รอส่งเอกสาร">รอส่งเอกสาร</option>
						  <option value="รอตัดสินใจ">รอตัดสินใจ</option>
						  <option value="อนุมัติ">อนุมัติ</option>
						  <option value="ไม่อนุมัติ">ไม่อนุมัติ</option>
						  </select>
						  </div>
					  </div>            
					  <br><br>					  
                      <input type="submit" name="sms" id="sms" value="ยืนยัน" class="btn btn-success" />
                      <button type="button" class="btn btn-default" data-dismiss="modal">ยกเลิก</button>
                    </form>
				</div>		
		</div> 					
    </div>
</div>
<!----------------------------------------------------------------------------------------------------->

<script>

function setEdit(pid,username,gender,birth,status)
{ 
  $('#pid').val(pid);   
  $('#username').val(username);      
  $('#gender').val(gender);         
  $('#birth').val(birth);     
  $('#status').val(status);   
}

function setSMS(uid,username)
{ 
  $('#uid').val(uid);   
  $('#receiver').val(username);      
}

function setAPP(uid,username,registrarid,registrar)
{ 
	$('#appid').val(uid);   
	$('#appusername').val(username); 
	$('#registrarid').val(registrarid); 
	$('#registrar').val(registrar); 
}

 $(document).ready(function()
 {
      $('#add').click(function()
      {
           $('#insert').val("บันทึก");
           $('#insert_form')[0].reset();
      });
	  
      $('#insert_form').on("submit", function(event){
           event.preventDefault();
           if($('#pid').val() == "")            alert("กรุณากรอกเลขที่บัตรประชาชน");
           else if($('#pn').val() == '' )       alert("กรุณากรอก ชื่อ-นามสกุลผู้ป่วย");
           else
           {
                  document.adddata.submit();
           }
      });

      $('#edit_form').on("submit", function(event){
           event.preventDefault();
           document.editdata.submit();
      });
	  
      $('#sms_form').on("submit", function(event){
           event.preventDefault();
		   if($('#message').val() == "") alert("กรุณากรอกข้อความที่ต้องการส่ง");
           else document.smsdata.submit();
      });	 
	  
	  $('#app_form').on("submit", function(event){
           event.preventDefault();
		   document.appdata.submit();
      });


	  
	  
 });
 </script>

