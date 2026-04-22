<?php
// ============================================================
//  logout.php  –  Termina la sessione
// ============================================================
require_once 'config.php';

startSecureSession();
session_unset();
session_destroy();

header('Location: index.php');
exit;
