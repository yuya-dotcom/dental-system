<?php
// config/mailer.php
// Kept for backward compatibility. The single createMailer() implementation
// lives in controllers/mailer.php (credentials come from config/secrets.php).
// Loading it via require_once means including either file never redeclares
// createMailer().

require_once __DIR__ . '/../controllers/mailer.php';
