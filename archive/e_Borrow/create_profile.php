<?php
// create_profile.php
// (โค้ด PHP ... เหมือนเดิม ...)
session_start();
require_once('includes/line_config.php');
if (!isset($_SESSION['line_id_to_register'])) {
    header("Location: login.php");
    exit;
}
$default_name = isset($_SESSION['line_name_to_register']) ? htmlspecialchars($_SESSION['line_name_to_register']) : '';
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างโปรไฟล์ผู้ใช้งาน</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            /* (ตัวแปรสีสำรอง) */
            --color-primary: #0B6623;
            --color-primary-dark: #084C1A;
            --color-page-bg: #B7E5CD;
            --color-content-bg: #FFFFFF;
            --border-radius-main: 12px;
            --box-shadow-main: 0 4px 12px rgba(0, 0, 0, 0.08);
            --border-color: #ddd;
        }

        body {
            background-color: var(--color-page-bg);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px 0;
        }

        .profile-container {
            background: var(--color-content-bg);
            padding: 30px 40px;
            border-radius: var(--border-radius-main);
            box-shadow: var(--box-shadow-main);
            width: 500px;
            max-width: 100%;
        }

        .form-group {
            margin-bottom: 15px;
            text-align: left;
        }

        .form-group label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            box-sizing: border-box;
        }

        .btn-submit {
            display: block;
            width: 100%;
            padding: 12px;
            background-color: var(--color-primary);
            /* (สีเขียวเข้ม) */
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .btn-submit:hover {
            background-color: var(--color-primary-dark);
        }
    </style>
</head>

<body>

    <div class="profile-container">
        <h2 style="text-align: center; color: var(--color-primary);">สร้างโปรไฟล์</h2>
        <p style="text-align: center; margin-bottom: 25px;">กรุณากรอกข้อมูลครั้งแรกเพื่อลงทะเบียน</p>

        <form action="process/save_profile.php" method="POST" id="profileForm">

            <div class="form-group">
                <label for="full_name">1. ชื่อ-นามสกุล <span style="color:red;">*</span></label>
                <input type="text" name="full_name" id="full_name" value="<?php echo $default_name; ?>" required>
            </div>

            <div class="form-group">
                <label for="department">2. คณะ/หน่วยงาน/สถาบัน</label>
                <input type="text" name="department" id="department">
            </div>

            <div class="form-group">
                <label for="status">3. สถานภาพ <span style="color:red;">*</span></label>
                <select name="status" id="status" required onchange="checkOtherStatus(this.value)">
                    <option value="">--- กรุณาเลือก ---</option>
                    <option value="student">นักศึกษา</option>
                    <option value="teacher">อาจารย์</option>
                    <option value="staff">เจ้าหน้าที่</option>
                    <option value="other">อื่นๆ (โปรดระบุ)</option>
                </select>
            </div>

            <div class="form-group" id="other_status_group" style="display: none;">
                <label for="status_other">โปรดระบุสถานภาพ "อื่นๆ":</label>
                <input type="text" name="status_other" id="status_other">
            </div>

            <div class="form-group">
                <label for="student_personnel_id">4. รหัสผู้ใช้งาน/บุคลากร</label>
                <input type="text" name="student_personnel_id" id="student_personnel_id">
            </div>

            <div class="form-group">
                <label for="phone_number">5. เบอร์โทรศัพท์</label>
                <input type="text" name="phone_number" id="phone_number">
            </div>

            <div class="form-group" id="terms_agree_group" style="margin-top: 20px; padding: 10px; background: #f8f8f8; border-radius: 8px; text-align: left;">
                <input type="checkbox" name="terms_agree" id="terms_agree" value="yes" required style="width: 16px; height: 16px; margin-right: 10px;">
                <label for="terms_agree" style="font-weight: normal; display: inline;">
                    ข้าพเจ้ายอมรับ
                    <a href="javascript:void(0);" onclick="openTermsPopup()" style="color: var(--color-primary); text-decoration: underline;">
                        ข้อตกลงและเงื่อนไขการใช้งาน
                    </a>
                </label>
            </div>

            <button type="submit" class="btn-loan" id="submitBtn" disabled> บันทึกข้อมูลและเริ่มใช้งาน
            </button>

        </form>
    </div>
   <script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/student_app.js"></script>
</body>

</html>