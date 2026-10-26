<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/env.php';

function getActiveEmail(PDO $db) {
    $stmt = $db->query("SELECT * FROM email_settings ORDER BY updated_at DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}function sendEmail(PDO $db, string $subject, string $bodyHtml, string $bodyPlain = ''): bool {

    // Ambil email aktif dari DB
    $active = getActiveEmail($db);

    if (!$active) {
        error_log("Tidak ada email aktif di database.");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'];
        $mail->Password   = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = intval($_ENV['SMTP_PORT']);

        $mail->setFrom($_ENV['MAIL_FROM'], $_ENV['MAIL_FROM_NAME']);

        $mail->addAddress($active['mail_to'], $active['mail_to_name']);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyPlain !== '' ? $bodyPlain : strip_tags($bodyHtml);

        return $mail->send();

    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
        return false;
    }
}