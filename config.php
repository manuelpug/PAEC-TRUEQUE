<?php
$host = 'sql302.infinityfree.com'; 
$user = 'if0_41409779';           
$pass = 'anonymoX3000';           
$db   = 'if0_41409779_trueque_ecologico'; 

$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    header('Content-Type: application/json');
    die(json_encode(["error" => "Error de conexión a la base de datos"]));
}
?>
