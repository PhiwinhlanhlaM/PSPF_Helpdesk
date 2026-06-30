<?php
// mail_config.php

use PHPMailer\PHPMailer\PHPMailer;

require __DIR__ . '/vendor/autoload.php'; // Adjust if needed

function getMailer() {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = '192.168.1.15'; // <-- Replace with your mail server
    $mail->SMTPAuth = false;                      // false if no auth is required
   // $mail->Username = ''; // A mailbox or service account
   // $mail->Password = '';        // Mailbox password
    $mail->SMTPSecure = '';                   // 'ssl', 'tls', or '' if none
    $mail->Port = 25;                           // 25, 465, or 587 depending on server
    $mail->setFrom('administrator@pspf.co.sz', 'Transport Booking System');
    return $mail;
    
}
