<?php
session_start();

// Redirect to appropriate page
if (isset($_SESSION['user_id'])) {
    header('Location: book.php');
} else {
    header('Location: login.php');
}
exit;
?>
