<?php
require_once 'config.php';

if (isset($_POST['login']) && isset($_POST['password'])) {
    if ($_POST['login'] === APP_USER && $_POST['password'] === APP_PASS) {
        $_SESSION['logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = "Неверный логин или пароль";
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css" rel="stylesheet">
    <style>
        body { display: flex; min-height: 100vh; flex-direction: column; justify-content: center; background: #f5f5f5; }
        .login-card { max-width: 400px; margin: 0 auto; padding: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card login-card z-depth-2">
            <div class="card-content">
                <span class="card-title center-align">Файловый менеджер</span>
                <?php if(isset($error)): ?>
                    <div class="card-panel red lighten-4 red-text text-darken-4"><?= $error ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="input-field">
                        <input id="login" type="text" name="login" required>
                        <label for="login">Логин</label>
                    </div>
                    <div class="input-field">
                        <input id="password" type="password" name="password" required>
                        <label for="password">Пароль</label>
                    </div>
                    <button class="btn waves-effect waves-light w-100" style="width:100%" type="submit">Войти</button>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
</body>
</html>