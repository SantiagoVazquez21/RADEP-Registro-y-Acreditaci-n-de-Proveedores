<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Usuario</title>
    <link rel="stylesheet" type="text/css" href="./estilos.css">
    <style>
      body {
        background: linear-gradient(135deg, #a9bec7 0%, #8ba3b0 50%, #6b8a9a 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .register-card {
        background: #d9e3e6;
        border: 2px solid #5092ab;
        border-radius: 18px;
        box-shadow: 0 8px 32px rgba(80,146,171,0.18);
        padding: 2.5rem 2rem 2rem 2rem;
        max-width: 420px;
        width: 100%;
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        align-items: center;
      }
      .register-title {
        text-align: center;
        font-size: 2rem;
        font-weight: bold;
        color: #5092ab;
        margin-bottom: 0.5rem;
      }
      .register-subtitle {
        text-align: center;
        color: #3b82f6;
        font-size: 1rem;
        margin-bottom: 1.5rem;
      }
      .register-form {
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
      .register-form label {
        color: #222;
        font-weight: 600;
        margin-bottom: 0.3rem;
        display: block;
        width: 100%;
        text-align: left;
      }
      .register-form input, .register-form select {
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
      .register-form input:focus, .register-form select:focus {
        border-color: #3b82f6;
        outline: none;
      }
      .register-btn {
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
      .register-btn:hover {
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
      }
      .register-links {
        text-align: center;
        margin-top: 1rem;
        color: #222;
        font-size: 0.98rem;
      }
      .register-links a {
        color: #5092ab;
        text-decoration: underline;
        font-size: 0.98rem;
        margin: 0 0.2rem;
      }
      @media (max-width: 600px) {
        .register-card {
          padding: 1.2rem 0.5rem 1.5rem 0.5rem;
          max-width: 98vw;
        }
        .register-form {
          padding: 1.2rem 0.3rem 1rem 0.3rem;
          max-width: 98vw;
        }
        .register-title {
          font-size: 1.3rem;
        }
      }
    </style>
</head>
<body>
  <div class="register-card">
    <img src="images/logo-radep.svg" alt="Logo RADEP" style="height:60px; display:block; margin: 0 auto 1rem auto;"/>
    <div class="register-title">RADEP</div>
    <div class="register-subtitle">Registro de usuario</div>
    <form class="register-form" action="alta.php" method="post" autocomplete="off">
        <!-- Public company registration disabled: only admin can create companies -->
        <div class="card">
          <p>El registro público de empresas ha sido deshabilitado. Contacta con el administrador para crear una cuenta de empresa.</p>
        </div>
    </form>
    <div class="register-links">
      ¿Ya tienes cuenta? <a href="index.php">Iniciar sesión</a>
    </div>
  </div>
</body>
</html>
<?php
    //Conexion con la base
    include ("dbconn.php");
    //$conn = mysqli_connect("localhost","root","","nusuario");
    ?>
    <?php    
   // if(isset($_POST['Enviar'])) {
    //if(strlen($_POST['nombre']) >= 1 && strlen($_POST['apellido'] >= 1 ) && strlen($_POST['email']) >= 1 && strlen($_POST['User']) >= 1 && strlen($_POST['password']) === $_POST['Cpassword']>= 1 && strlen ($_POST['rol'])){
   if(isset($_POST['Enviar'])) {
    if (
        !empty($_POST['nombre']) &&
        !empty($_POST['apellido']) &&
        !empty($_POST['email']) &&
        !empty($_POST['User']) &&
        !empty($_POST['password']) &&
        !empty($_POST['Cpassword']) &&
        !empty($_POST['rol']) &&
        $_POST['password'] === $_POST['Cpassword']
    ) {
        $nombre = trim($_POST['nombre']);
        $apellido = trim($_POST['apellido']);
        $email = trim($_POST['email']);
        $User = trim($_POST['User']);
        $password = $_POST['password'];
        $rol = $_POST['rol'];

        $pass_cifrada = password_hash($password, PASSWORD_DEFAULT, ["cost" => 10]);

        $consulta = "INSERT INTO registronuevo (nombre, apellido, email, user, pass, rol) 
                     VALUES ('$nombre', '$apellido', '$email', '$User', '$pass_cifrada', '$rol')";
        $resultado = mysqli_query($conn, $consulta);

        if ($resultado) {
            echo '<h3 class="ok">¡Te has inscripto correctamente! Ahora puedes iniciar sesión.</h3>';
            header("Location: index.php");
            exit();
        } else {
            echo '<h3 class="bad">¡Ups ha ocurrido un error al registrar!</h3>';
        }
    } else {
        echo '<h3 class="bad">¡Por favor complete todos los campos y asegúrese que las contraseñas coincidan!</h3>';
    }
}

    ?>