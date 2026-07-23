<?php

declare(strict_types=1);

session_start();

$_SESSION = [];

header('Location: ../index.php');
exit();

?>