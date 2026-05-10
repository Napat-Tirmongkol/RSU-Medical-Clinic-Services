<?php
$accessToken = "eRvLk/B6y3H7diTNRBohhlLe+lrY+YzoYITuSa6q/ilpZZl+9NS0aLhG7APIdNNE5iw5I6AmZWRk77jAtb+EwwVfYiFCI9NHdc1u0YwxSx70PsSSX7+XW4ZIQXD6RTvoxErRa5TYINIObz6YhXZTHgdB04t89/1O/w1cDnyilFU=";
$conn = new mysqli('127.0.0.1', 'rsu', 'Dodeep4321;','rsu', '3366');
mysqli_set_charset($conn, 'utf8');

function DateThai($strDate)
{
  $strYear = date("Y",strtotime($strDate)) + 543;
  $strMonth= date("n",strtotime($strDate));
  $strDay= date("j",strtotime($strDate));
  $strHour= date("H",strtotime($strDate));
  $strMinute= date("i",strtotime($strDate));
  $strSeconds= date("s",strtotime($strDate));
  $strMonthCut = Array("","มกราคม","กุมภาพันธ์","มีนาคม","เมษายน","พฤษภาคม","มิถุนายน","กรกฏาคม","สิงหาคม","กันยายน","ตุลาคม","พฤศจิกายน","ธันวาคม");
  $strMonthThai=$strMonthCut[$strMonth];
  return "$strDay $strMonthThai $strYear";
}

?>
