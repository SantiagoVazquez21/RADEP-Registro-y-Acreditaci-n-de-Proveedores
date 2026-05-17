<?php
session_start(); include('dbconn.php'); if(!isset($_SESSION['tipo_usuario'])||$_SESSION['tipo_usuario']!=='admin'){ header('Location:index.php'); exit(); }
$action = $_POST['action'] ?? $_GET['action'] ?? null;

function redirect_back($section=''){
    $url = 'admin.php'; if($section) $url .= '?section='.$section; header('Location: '.$url); exit();
}

// helper to set flash message
function flash($type, $msg){ $_SESSION['flash'] = ['type'=>$type, 'msg'=>$msg]; }

switch($action){
    case 'create_empresa':
        $nombre = trim($_POST['nombre']??'');
        $user = trim($_POST['user']??'');
        $pass = $_POST['pass']??'';
        $email = trim($_POST['email']??'');

        if ($nombre === '' || $user === '' || $pass === '') {
            flash('error','Complete los campos requeridos.');
            redirect_back('empresas');
        }

        // Verificar que el username no exista ya en users
        $chk = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        if ($chk) {
            $chk->bind_param('s', $user);
            $chk->execute();
            if (method_exists($chk, 'get_result')) {
                $res = $chk->get_result();
                if ($res && $res->fetch_assoc()) { flash('error','El usuario ya existe en la tabla users.'); redirect_back('empresas'); }
            } else {
                $chk->bind_result($fuid);
                if ($chk->fetch()) { flash('error','El usuario ya existe en la tabla users.'); redirect_back('empresas'); }
            }
            $chk->close();
        }

        // Iniciar transacción para crear user + empresa
        $conn->begin_transaction();
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $createdUserId = null;
        $uStmt = $conn->prepare("INSERT INTO users (username,password,role,nombre,email) VALUES (?,?,?,?,?)");
        if ($uStmt) {
            $role = 'empresa';
            // Guardamos el nombre de la empresa en el campo nombre del usuario para facilitar identificación
            $uStmt->bind_param('sssss', $user, $hash, $role, $nombre, $email);
            if ($uStmt->execute()) {
                $createdUserId = $conn->insert_id;
            } else {
                $conn->rollback();
                flash('error','Error creando usuario: '.$uStmt->error);
                redirect_back('empresas');
            }
            $uStmt->close();
        } else {
            $conn->rollback();
            flash('error','Error preparando creación de usuario.');
            redirect_back('empresas');
        }

        // Ahora crear la empresa (almacenamos la contraseña hasheada también en empresas.pass para compatibilidad)
        $eStmt = $conn->prepare("INSERT INTO empresas (nombre,user,pass,email) VALUES (?,?,?,?)");
        if ($eStmt) {
            $eStmt->bind_param('ssss', $nombre, $user, $hash, $email);
            if (!$eStmt->execute()) {
                $conn->rollback();
                flash('error','Error creando empresa: '.$eStmt->error);
                redirect_back('empresas');
            }
            $eStmt->close();
        } else {
            $conn->rollback();
            flash('error','Error preparando creación de empresa.');
            redirect_back('empresas');
        }

        // Commit y registro de auditoría
        $conn->commit();
        if (function_exists('audit_log')) audit_log($conn, $_SESSION['usuario'], 'create_empresa', "Empresa $nombre creada (user:$user, users.id:$createdUserId)");
        flash('success','Empresa y usuario creados correctamente.');
        redirect_back('empresas');
    case 'edit_empresa':
        $id=intval($_POST['id']??0); $nombre=$_POST['nombre']??''; $user=$_POST['user']??''; $email=$_POST['email']??'';
    $u=$conn->prepare("UPDATE empresas SET nombre=?, user=?, email=? WHERE id=?"); $u->bind_param('sssi',$nombre,$user,$email,$id); if($u->execute()){ if(function_exists('audit_log')) audit_log($conn,$_SESSION['usuario'],'edit_empresa',"Empresa $id actualizada"); flash('success','Empresa actualizada.'); } else { flash('error','Error al actualizar: '.$u->error); }
    redirect_back('empresas');
    case 'toggle_empresa':
    // 'estado' column removed; no-op for toggle. Keep audit entry and inform user.
    $id=intval($_POST['id']??0);
    if(function_exists('audit_log')) audit_log($conn,$_SESSION['usuario'],'toggle_empresa_noop',"Empresa $id toggle attempted but 'estado' removed");
    flash('error','La columna estado fue eliminada; esta acción ya no es soportada.');
    redirect_back('empresas');
    case 'delete_empresa':
    // Convert to hard delete now that estado column is removed
    $id=intval($_POST['id']??0); $u=$conn->prepare("DELETE FROM empresas WHERE id=?"); $u->bind_param('i',$id); if($u->execute()){ if(function_exists('audit_log')) audit_log($conn,$_SESSION['usuario'],'delete_empresa',"Empresa $id eliminada"); flash('success','Empresa eliminada.'); } else { flash('error','Error al eliminar: '.$u->error); } redirect_back('empresas');
    case 'toggle_proveedor':
    // 'estado' column removed; no-op for toggle.
    $id=intval($_POST['id']??0);
    if(function_exists('audit_log')) audit_log($conn,$_SESSION['usuario'],'toggle_proveedor_noop',"Proveedor $id toggle attempted but 'estado' removed");
    flash('error','La columna estado fue eliminada; esta acción ya no es soportada.');
    redirect_back('proveedores');
    case 'approve_document':
    $id=intval($_POST['id']??0); $coment=$_POST['comentario']??('Aprobado por '.$_SESSION['usuario']); $u=$conn->prepare("UPDATE documentos SET status='aprobado', comentario=? WHERE id=?"); $u->bind_param('si',$coment,$id); if($u->execute()){ if(function_exists('audit_log')) audit_log($conn,$_SESSION['usuario'],'approve_document',"Documento $id aprobado"); flash('success','Documento aprobado.'); } else { flash('error','Error: '.$u->error); } redirect_back('documentos');
    case 'reject_document':
    $id=intval($_POST['id']??0); $coment=$_POST['comentario']??('Rechazado por '.$_SESSION['usuario']); $u=$conn->prepare("UPDATE documentos SET status='rechazado', comentario=? WHERE id=?"); $u->bind_param('si',$coment,$id); if($u->execute()){ if(function_exists('audit_log')) audit_log($conn,$_SESSION['usuario'],'reject_document',"Documento $id rechazado"); flash('success','Documento rechazado.'); } else { flash('error','Error: '.$u->error); } redirect_back('documentos');
    case 'create_admin':
    $username=$_POST['username']??''; $pass=$_POST['password']??''; $nombre=$_POST['nombre']??''; $apellido=$_POST['apellido']??''; $email=$_POST['email']??''; $hash=password_hash($pass,PASSWORD_DEFAULT); $stmt=$conn->prepare("INSERT INTO users (username,password,role,nombre,apellido,email) VALUES (?,?,'admin',?,?,?)"); $stmt->bind_param('sssss',$username,$hash,$nombre,$apellido,$email); if($stmt->execute()){ if(function_exists('audit_log')) audit_log($conn,$_SESSION['usuario'],'create_admin',"Admin $username creado"); flash('success','Admin creado.'); } else { flash('error','Error creando admin: '.$stmt->error); } redirect_back('admins');
    case 'edit_admin':
    $id=intval($_POST['id']??0); $nombre=$_POST['nombre']??''; $apellido=$_POST['apellido']??''; $email=$_POST['email']??''; $u=$conn->prepare("UPDATE users SET nombre=?, apellido=?, email=? WHERE id=?"); $u->bind_param('sssi',$nombre,$apellido,$email,$id); if($u->execute()){ if(function_exists('audit_log')) audit_log($conn,$_SESSION['usuario'],'edit_admin',"Admin $id actualizado"); flash('success','Admin actualizado.'); } else { flash('error','Error: '.$u->error); } redirect_back('admins');
    case 'delete_admin':
    $id=intval($_POST['id']??0); if($_SESSION['usuario_id']==$id){ flash('error','No puedes eliminarte a ti mismo.'); redirect_back('admins'); } $u=$conn->prepare("DELETE FROM users WHERE id=?"); $u->bind_param('i',$id); if($u->execute()){ if(function_exists('audit_log')) audit_log($conn,$_SESSION['usuario'],'delete_admin',"Admin $id eliminado"); flash('success','Admin eliminado.'); } else { flash('error','Error eliminando admin: '.$u->error); } redirect_back('admins');
    case 'change_password':
    $current=$_POST['current_password']??''; $new=$_POST['new_password']??''; $user=$_SESSION['usuario']; $s=$conn->prepare("SELECT password FROM users WHERE username=? LIMIT 1"); $s->bind_param('s',$user); $s->execute(); $s->bind_result($hash); if($s->fetch()){ if(password_verify($current,$hash)){ $newhash=password_hash($new,PASSWORD_DEFAULT); $u=$conn->prepare("UPDATE users SET password=? WHERE username=?"); $u->bind_param('ss',$newhash,$user); if($u->execute()){ if(function_exists('audit_log')) audit_log($conn,$user,'change_password','Admin changed own password'); flash('success','Contraseña cambiada.'); } else { flash('error','Error cambiando contraseña: '.$u->error); } } else { flash('error','Contraseña actual incorrecta.'); } }
    redirect_back('settings');
}

// Default redirect
redirect_back();
?>
