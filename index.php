<?php
session_start();

// Initialize game state
if (!isset($_SESSION['game'])) {
    $_SESSION['game'] = [
        'score' => 0,
        'level' => 1,
        'game_over' => false
    ];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_score') {
        $_SESSION['game']['score'] = (int)$_POST['score'];
        $_SESSION['game']['level'] = floor($_SESSION['game']['score'] / 50) + 1;
        echo json_encode(['success' => true]);
    }
    elseif ($_POST['action'] === 'game_over') {
        $_SESSION['game']['game_over'] = true;
        echo json_encode(['success' => true, 'final_score' => $_SESSION['game']['score']]);
    }
    elseif ($_POST['action'] === 'restart') {
        $_SESSION['game'] = [
            'score' => 0,
            'level' => 1,
            'game_over' => false
        ];
        echo json_encode(['success' => true]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Car Game</title>
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: #333;
            font-family: Arial, sans-serif;
        }
        #game-container {
            text-align: center;
        }
        canvas {
            border: 2px solid #fff;
            background: #000;
        }
        #score-board {
            color: white;
            font-size: 24px;
            margin-bottom: 10px;
        }
        #game-over {
            display: none;
            color: red;
            font-size: 32px;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        #restart-btn {
            padding: 10px 20px;
            font-size: 18px;
            cursor: pointer;
            display: none;
        }
    </style>
</head>
<body>
    <div id="game-container">
        <div id="score-board">Score: 0 | Level: 1</div>
        <canvas id="gameCanvas" width="400" height="600"></canvas>
        <div id="game-over">Game Over!</div>
        <button id="restart-btn">Restart</button>
    </div>

    <script>
        const canvas = document.getElementById('gameCanvas');
        const ctx = canvas.getContext('2d');
        const scoreBoard = document.getElementById('score-board');
        const gameOverText = document.getElementById('game-over');
        const restartBtn = document.getElementById('restart-btn');

        // Game variables
        let car = {
            x: 180,
            y: 500,
            width: 40,
            height: 60,
            speed: 5
        };
        let obstacles = [];
        let score = 0;
        let level = 1;
        let gameOver = false;
        let obstacleSpeed = 3;

        // Car image
        const carImg = new Image();
        carImg.src = 'https://img.icons8.com/color/48/000000/car.png';

        // Keyboard controls
        let leftPressed = false;
        let rightPressed = false;

        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') leftPressed = true;
            if (e.key === 'ArrowRight') rightPressed = true;
        });
        document.addEventListener('keyup', (e) => {
            if (e.key === 'ArrowLeft') leftPressed = false;
            if (e.key === 'ArrowRight') rightPressed = false;
        });

        // Generate obstacle
        function spawnObstacle() {
            const x = Math.random() * (canvas.width - 40);
            obstacles.push({ x, y: -40, width: 40, height: 40 });
        }

        // Update game
        function updateGame() {
            if (gameOver) return;

            // Move car
            if (leftPressed && car.x > 0) car.x -= car.speed;
            if (rightPressed && car.x < canvas.width - car.width) car.x += car.speed;

            // Move obstacles
            obstacles.forEach(obstacle => {
                obstacle.y += obstacleSpeed;
            });

            // Check collision
            obstacles.forEach(obstacle => {
                if (obstacle.y + obstacle.height > car.y &&
                    obstacle.y < car.y + car.height &&
                    obstacle.x + obstacle.width > car.x &&
                    obstacle.x < car.x + car.width) {
                    gameOver = true;
                    gameOverText.style.display = 'block';
                    restartBtn.style.display = 'block';
                    // Send game over to server
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=game_over&score=' + score
                    });
                }
            });

            // Remove off-screen obstacles
            obstacles = obstacles.filter(obs => obs.y < canvas.height);

            // Update score and level
            score++;
            level = Math.floor(score / 500) + 1;
            obstacleSpeed = 3 + level * 0.5;
            scoreBoard.textContent = `Score: ${score} | Level: ${level}`;

            // Spawn obstacles randomly
            if (Math.random() < 0.02 * level) spawnObstacle();

            // Update server score
            if (score % 100 === 0) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=update_score&score=' + score
                });
            }
        }

        // Render game
        function draw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            // Draw road
            ctx.fillStyle = 'gray';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Draw car
            ctx.drawImage(carImg, car.x, car.y, car.width, car.height);

            // Draw obstacles
            ctx.fillStyle = 'red';
            obstacles.forEach(obstacle => {
                ctx.fillRect(obstacle.x, obstacle.y, obstacle.width, obstacle.height);
            });
        }

        // Game loop
        function gameLoop() {
            if (!gameOver) {
                updateGame();
                draw();
            }
            requestAnimationFrame(gameLoop);
        }

        // Restart game
        restartBtn.addEventListener('click', () => {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=restart'
            }).then(() => {
                score = 0;
                level = 1;
                obstacles = [];
                car.x = 180;
                gameOver = false;
                obstacleSpeed = 3;
                gameOverText.style.display = 'none';
                restartBtn.style.display = 'none';
                scoreBoard.textContent = `Score: ${score} | Level: ${level}`;
            });
        });

        // Start game
        gameLoop();
    </script>
</body>
</html>