<!DOCTYPE html>
<html>
<head>
    <title>KBank Video Journey</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: 'Prompt', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #d3d3d3; /* พื้นหลังสีเทา */
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1200px; /* รองรับวิดีโอขนาดใหญ่ */
            margin: 0 auto;
            text-align: center;
            background: #fff; /* กล่องสีขาวเพื่อความสะอาดตา */
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2); /* เงาอ่อนๆ */
        }
        h2 {
            font-size: 2em;
            margin-bottom: 20px;
            color: #00A950; /* สีเขียว KBank */
            font-weight: 600;
        }
        video {
            width: 100%;
            max-width: 1000px; /* วิดีโอขนาดใหญ่ */
            border-radius: 8px;
            border: 1px solid #e0e0e0; /* กรอบบางๆ */
        }
        .choices {
            display: none;
            margin-top: 15px; /* ตัวเลือกใกล้วิดีโอ */
            padding: 15px;
            background: #f0f7f2; /* พื้นหลังเขียวอ่อนเพื่อกลมกลืน */
            border-radius: 8px;
            border: 1px solid #d0e8d6; /* ขอบเขียวอ่อน */
            opacity: 0;
            transition: opacity 0.3s ease; /* แอนิเมชัน fade เบาๆ */
        }
        .choices.active {
            display: block;
            opacity: 1;
        }
        .choice-btn {
            padding: 12px 25px;
            margin: 8px;
            font-size: 1.1em;
            font-weight: 500;
            cursor: pointer;
            background: #00A950; /* สีเขียว KBank */
            color: #fff;
            border: none;
            border-radius: 8px;
            transition: background 0.2s ease;
        }
        .choice-btn:hover {
            background: #00873d; /* เขียวเข้มขึ้นเมื่อ hover */
        }
        .choice-btn:active {
            background: #006d31; /* เขียวเข้มสุดเมื่อกด */
        }
        .checkpoint {
            margin-bottom: 10px;
            font-size: 1.2em;
            color: #00A950; /* สีเขียว KBank */
            font-weight: 500;
        }
        .error {
            color: #d32f2f;
            background: #ffebee;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            font-weight: 500;
        }
        @media (max-width: 600px) {
            .container {
                padding: 15px;
            }
            h2 {
                font-size: 1.6em;
            }
            video {
                max-width: 100%;
            }
            .choice-btn {
                padding: 10px 20px;
                font-size: 1em;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <h2>การเดินทางของคุณ</h2>
        <video id="myVideo" controls>
            <source src="Uploads/TheBest.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>
        
        <div id="choices" class="choices">
            <div class="checkpoint">✅ คุณผ่านขั้นตอนนี้แล้ว! เลือกเส้นทางต่อไป:</div>
            <button class="choice-btn" onclick="playNextVideo('om1.mp4')">เส้นทาง 1: ก้าวต่อไป</button>
            <button class="choice-btn" onclick="playNextVideo('om2.mp4')">เส้นทาง 2: ทางเลือกใหม่</button>
        </div>
    </div>

    <script>
        const video = document.getElementById('myVideo');
        const choices = document.getElementById('choices');

        video.onended = function() {
            choices.classList.add('active');
        };

        function playNextVideo(videoFile) {
            video.src = 'Uploads/' + videoFile;
            choices.classList.remove('active');
            video.play();
        }
    </script>

    <?php
    // ตรวจสอบไฟล์วิดีโอ
    $videos = [
        'Uploads/TheBest.mp4' => 'วิดีโอเริ่มต้น',
        'Uploads/om1.mp4' => 'เส้นทาง 1',
        'Uploads/om2.mp4' => 'เส้นทาง 2'
    ];

    foreach ($videos as $video => $name) {
        if (!file_exists($video)) {
            echo "<p class='error'>ไม่พบไฟล์วิดีโอ: $name ($video)</p>";
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['choice'])) {
            $chosenVideo = $_POST['choice'];
            echo "<script>playNextVideo('$chosenVideo');</script>";
        }
    }
    ?>
</body>
</html>