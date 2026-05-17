<?php

//Include Configuration File

include('dbconn.php'); // Asegúrate de incluir la conexión a la base de datos



?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión</title>
    <link rel="stylesheet" type="text/css" href="./estilos.css">
    <style>
      body {
        background: linear-gradient(135deg, #a9bec7 0%, #8ba3b0 50%, #6b8a9a 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .login-card {
        background: #d9e3e6;
        border: 2px solid #5092ab;
        border-radius: 18px;
        box-shadow: 0 8px 32px rgba(80,146,171,0.18);
        padding: 2.5rem 2rem 2rem 2rem;
        max-width: 400px;
        width: 100%;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        align-items: center;
      }
      .login-title {
        text-align: center;
        font-size: 2rem;
        font-weight: bold;
        color: #5092ab;
        margin-bottom: 0.5rem;
      }
      .login-subtitle {
        text-align: center;
        color: #3b82f6;
        font-size: 1rem;
        margin-bottom: 1.5rem;
      }
      .login-form {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 4px 18px rgba(80,146,171,0.10);
        padding: 2rem 1.2rem 1.2rem 1.2rem;
        width: 100%;
        max-width: 340px;
        display: flex;
        flex-direction: column;
        align-items: center;
      }
      .login-form label {
        color: #222;
        font-weight: 600;
        margin-bottom: 0.3rem;
        display: block;
        width: 100%;
        text-align: left;
      }
      .login-form input, .login-form select {
        width: 100%;
        padding: 0.7rem 1rem;
        border-radius: 10px;
        border: 1.5px solid #5092ab;
        background: #f8fafc;
        color: #222;
        font-size: 1rem;
        margin-bottom: 1rem;
        transition: border 0.2s;
        box-sizing: border-box;
      }
      .login-form input:focus, .login-form select:focus {
        border-color: #3b82f6;
        outline: none;
      }
      .login-btn {
        width: 100%;
        background: linear-gradient(135deg, #5092ab, #3b82f6);
        color: #fff;
        font-weight: bold;
        border: none;
        padding: 0.9rem 0;
        border-radius: 12px;
        cursor: pointer;
        font-size: 1.1rem;
        margin-top: 0.5rem;
        transition: background 0.3s, box-shadow 0.2s;
        box-shadow: 0 4px 18px rgba(80,146,171,0.13);
        letter-spacing: 0.01em;
      }
      .login-btn:hover {
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      }
      .login-links {
        text-align: center;
        margin-top: 1rem;
        color: #222;
        font-size: 0.98rem;
      }
      .login-links a {
        color: #5092ab;
        text-decoration: underline;
        font-size: 0.98rem;
        margin: 0 0.2rem;
      }
      @media (max-width: 600px) {
        .login-card {
          padding: 1.2rem 0.5rem 1.5rem 0.5rem;
          max-width: 98vw;
        }
        .login-form {
          padding: 1.2rem 0.3rem 1rem 0.3rem;
          max-width: 98vw;
        }
        .login-title {
          font-size: 1.3rem;
        }
      }
    </style>
</head>
<body>
  <div class="login-card">
    <div class="login-title">RADEP</div>
    <div class="login-subtitle">Acceso al sistema</div>
    <form class="login-form" action="ingreso.php" method="POST" autocomplete="off">
      <label for="usuario">Usuario</label>
      <input type="text" name="usuario" id="usuario" required autocomplete="username">

      <label for="password">Contraseña</label>
      <input type="password" name="password" id="password" required autocomplete="current-password">

      <label for="rol">Rol</label>
      <select name="rol" id="rol" required>
        <option value="">Selecciona un rol</option>
        <option value="empresa">Empresa</option>
        <option value="proveedor">Proveedor</option>
      </select>

      <button type="submit" name="Enviar" class="login-btn">Ingresar</button>
    </form>
  </div>
</body>
</html>