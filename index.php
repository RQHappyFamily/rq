<?php
// กำหนด header เป็น image
header('Content-Type: image/jpeg');

// สร้างรูปภาพขนาด 1920x1080
$image = imagecreatetruecolor(1920, 1080);

// กำหนดสีพื้นหลัง (เช่น สีขาว)
$background = imagecolorallocate($image, 255, 255, 255);
imagefill($image, 0, 0, $background);

// หากต้องการเพิ่มรูปภาพจากไฟล์
// $source = imagecreatefromjpeg('path/to/image.jpg');
// imagecopyresized($image, $source, 0, 0, 0, 0, 1920, 1080, imagesx($source), imagesy($source));

// หรือเพิ่มข้อความตัวอย่าง
$text_color = imagecolorallocate($image, 0, 0, 0);
imagestring($image, 5, 50, 50, 'Image 1920x1080', $text_color);

// แสดงผลรูปภาพ
imagejpeg($image);

// ล้าง memory
imagedestroy($image);
?>
