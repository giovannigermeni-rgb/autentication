<?php
// ============================================================
//  logout.php  –  Termina la sessione
// ============================================================
require_once 'config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
session_unset();
session_destroy();

header('Location: index.php');
exit;
