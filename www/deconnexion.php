<?php
require __DIR__ . '/includes/bootstrap.php';

$_SESSION = [];
session_destroy();
session_start();
flash('info', 'Vous êtes déconnecté.');
rediriger('connexion.php');
