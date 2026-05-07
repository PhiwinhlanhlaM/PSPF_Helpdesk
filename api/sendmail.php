<?php
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'simphiweshongwe11@gmail.com';
    $mail->Password   = 's';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Recipients
    $mail->setFrom('simphiweshongwe11@gmail.com', 'Simphiwe');
    $mail->addAddress('simphiweshongwe11@gmail.com', 'Simphiwe');

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Test Email from XAMPP';
    $mail->Body    = 'This is a test email sent from XAMPP using PHPMailer.';

    $mail->send();
    echo '✅ Message has been sent';
} catch (Exception $e) {
    echo "❌ Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
