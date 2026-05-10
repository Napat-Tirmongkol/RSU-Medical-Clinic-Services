<?php


$conn = new mysqli('127.0.0.1', 'rsu', 'Dodeep4321;','rsu', '3366');
mysqli_set_charset($conn, 'utf8');
include 'lib.php';

$pid = $_GET['pid'];
$sql = "SELECT * FROM welfarecard WHERE pid = $pid";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
if( $row == 0)
{
	
}
else
{
$username = $row['username'];
$gender = $row['gender'];
$birth = $row['birth'];
$status  = $row['status'];
$Today = date('Y-m-d') ;
$diff = date_diff(date_create($Today),date_create($birth));
$dt = date_create($birth);
$dt->modify('+60 years');

$signature = $row['signature'];
$img = explode(',',$signature,2)[1];
$pic = 'data://text/plain;base64,'. $img;

if( $status == "อนุมัติ")
{
$registrar = $row['registrar'];
$sql = "SELECT * FROM welfareuser WHERE uid = $registrar";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
if( $row > 0)  $regname =  $row['username'];
}


//require('fpdf.php');
require('rotation.php');

class PDF extends PDF_Rotate
{
function RotatedText($x,$y,$txt,$angle)
{
    $this->Rotate($angle,$x,$y);
    $this->Text($x,$y,$txt);
    $this->Rotate(0);
}

function RotatedImage($file,$x,$y,$w,$h,$angle)
{
    //Image rotated around its upper-left corner
    $this->Rotate($angle,$x,$y);
    $this->Image($file,$x,$y,$w,$h);
    $this->Rotate(0);
}
}

//======================================================================
$pdf = new PDF();
$pdf->AliasNbPages();
//$pdf->SetMargins(20,10,15);
$pdf->AddFont('THSarabunNew','','THSarabunNew.php');
$pdf->AddFont('THSarabunNew','B','THSarabunNew_b.php');
$pdf->AddPage();
//$pdf->Image('RSU-MEDICAL-CLINIC.png',155,200,30);
$pdf->Image('./images/สปสช.jpg',80,20,45);
//$pdf->SetFont('THSarabunNew','B',80);
//$pdf->SetTextColor(240,220,220);
//$pdf->RotatedText(60,200,iconv('UTF-8', 'cp874', 'รับรองสำเนาถูกต้อง'),45);
//$pdf->SetTextColor(0,0,0);

$pdf->Ln(35);
$pdf->SetFont('THSarabunNew','B',18); 
$pdf->Cell(0, 10, iconv('UTF-8', 'cp874', 'แบบคำร้องลงทะเบียนสิทธิหลักประกันสุขภาพแห่งชาติ/ขอเปลี่ยนหน่วยบริการประจำ'),0,1,'C');
$pdf->SetFont('THSarabunNew','B',18);
$pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'หมายเลขอ้างอิงการลงทะเบียน :'),0,2,'C');
$pdf->Ln(5);
$pdf->SetFont('THSarabunNew','',14);
$pdf->SetX(55);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'เลขประจำตัวประชาชนผู้ลงทะเบียน : '.$pid),0,2);
$pdf->SetX(65);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ชื่อ-นามสกุลผู้ขอลงทะเบียน : '.$username),0,2);
$pdf->SetX(96);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'เพศ : '.$gender),0,2);
$pdf->SetX(87);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'เดือนปีเกิด : '. substr(DateThai($birth),2) ),0,2);
$pdf->SetX(95.5); $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'อายุ : '. $diff->y),0,2);
$pdf->SetX(95);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ที่อยู่ : 52/347'),0,2);
$pdf->SetX(85);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ตำบล/แขวง : หลักหก'),0,2);
$pdf->SetX(92);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'จังหวัด : เมืองปทุมธานี'),0,2);
$pdf->SetX(70);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'จังหวัดที่ลงทะเบียนใหม่ : เมืองปทุมธานี'),0,2);
$pdf->SetX(70);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'หน่วยบริการประจำใหม่ : คลินิกเวชกรรม มหาวิทยาลัยรังสิต (41392)'),0,2);
$pdf->SetX(69);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'หน่วยบริการปฐมภูมิใหม่ : คลินิกเวชกรรม มหาวิทยาลัยรังสิต (41392)'),0,2);
$pdf->SetX(61);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'หน่วยบริการที่รับการส่งต่อใหม่ : ร.พ.ปทุมธานี (10687)'),0,2);
$pdf->SetX(64);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'สิทธิหลักในการรับเข้าบริการ : สิทธิหลักประกันสุขภาพแห่งชาติ (UCS)'),0,2);
$pdf->SetX(79);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ประเภทสิทธิย่อย : ช่วงอายุ 12-59 ปี (89)'),0,2);
$pdf->SetX(82);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'วันที่เริ่มใช้สิทธิ : ' . DateThai($Today) ),0,2);
$pdf->SetX(72.5); $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'วันที่หมดอายุสิทธิย่อย : ' . DateThai($dt->format('Y-m-d'))),0,2);
$pdf->SetX(71);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ประเภทการลงทะเบียน : เปลี่ยนหน่วยบริการ'),0,2);
$pdf->SetX(59);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'เลขประจำตัวประชาชนผู้รับมอบ : '),0,2);
$pdf->SetX(64);   $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ชื่อ-นามสกุลผู้รับมอบอำนาจ :'),0,2);

$pdf->SetXY(15,184);   
$pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ข้าพเจ้าขอยืนยันว่าขณะยื่นคำร้องขอลงทะเบียนนี้ ข้าพเจ้ามิได้มีสิทธิอื่นใดที่รัฐจัดให้ (สิทธิข้าราชการ/รัฐวิสาหกิจ/ประกันสังคม/หน่วยงานรัฐอื่นๆ)'),0,2);
$pdf->SetXY(10,190);   
$pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'และในกรณีขอเปลี่ยนหน่วยบริกาารประจำ ข้าพเจ้าไม่ได้อยู่ในระหว่างพักรักษาตัวอยู่ในหน่วยบริการ'),0,2);
$pdf->SetXY(15,200);   
$pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'หากรายละเอียดข้างต้นไม่เป็นความจริง จะส่งผลให้การลงทะเบียนนี้เป็นโมฆะ และหากมีความเสียหายข้าพเจ้ายินดีรับผิดชอบ'),0,2);

$name = $username ;
//$pdf->Image('./sign/signature.png',125,10,80);

$pdf->SetXY(15,216); $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ลงชื่อ _________________________________ผู้ขอลงทะเบียน'),0,2);
$pdf->SetXY(15,222); $pdf->Cell(80, 6, iconv('UTF-8', 'cp874', '(' . $name  .')'),0,2,'C');
$pdf->SetXY(15,232); $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ลงชื่อ _________________________________ผู้ขอลงทะเบียนแทน(กรณีมอบอำนาจผู้อื่น)'),0,2);
$pdf->SetXY(15,241); $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', '      (_________________________________) เกี่ยวข้อเป็น___________________________'),0,2);
$pdf->SetXY(15,254); $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'ลงชื่อ _________________________________เจ้าหน้าที่ผู้รับลงทะเบียนและตรวจสอบข้อมูล'),0,2);
$pdf->SetXY(15,270); $pdf->Cell(0, 6, iconv('UTF-8', 'cp874', 'วันที่บันทึกข้อมูล ' . DateThai($Today) ),0,2);
//$pdf->Output('D', "certificate.pdf");
$pdf->Image($pic, 15,205,0,0,'png');

if( $status == "อนุมัติ" && $regname != NULL )
{
	$pdf->SetXY(15,260); $pdf->Cell(80, 6, iconv('UTF-8', 'cp874', '(' . $regname  .')'),0,2,'C');
	$pdf->Image('./signature/'.$registrar.'.png',28,243,50);
}

$pdf->Output();
}
?>
