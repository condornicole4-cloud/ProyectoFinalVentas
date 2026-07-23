<?php
//Esto siempre va arriba
declare(strict_types=1);

//Activar el reporte de errores
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

//Incluimos la conexión de la base de datos
require_once 'conexion.php';

//validamos que el usuario y la contrasñea hayan sido enviados por el formulario

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuarioInput = $_POST['usuario'];
    $passwordInput = $_POST['password'];

    try{
        //Consultamos el usuario de forma segura a la base de datos utilizando consultas de tipo statement
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND estado=1");
        // Ejecutar la consulta pasando el parámetro del usuario
        $stmt->execute([$usuarioInput]);
        $usuarioDB = $stmt->fetch(PDO::FETCH_ASSOC);
        
        //Evaluamos si el usuario y la contraseña son identicas/correctas
        // Soportar contraseñas con hash (password_verify) o en texto claro
        $passwordOk = false;
        if($usuarioDB){
            if(password_verify($passwordInput, (string)$usuarioDB['password_hash'])){
                $passwordOk = true;
            } elseif($passwordInput === $usuarioDB['password_hash']){
                $passwordOk = true;
            }
        }

        if($usuarioDB && $passwordOk){
            //Si son correctas, creamos la sesión del usuario
            $_SESSION['usuario_activo'] = [
                'id' => $usuarioDB['id'],
                'usuario' => $usuarioDB['usuario'],
                'nombre' => $usuarioDB['usuario'],
                'rol' => $usuarioDB['rol']
            ];
            header('Location: ../index.php');
            exit();
        }else{
         //Login fallido
         header('Location: ../index.php?error=1');
         exit();   
        }

    }catch(PDOException $e){

        die("Error en la base de datos: ". $e->getMessage());

    }
}else{
    //Si no se envió el formulario, redirigimos al index
    header('Location: ../index.php');
    exit();
}