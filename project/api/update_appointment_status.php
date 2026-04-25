<?php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../controllers/appointment_controller.php';

$body          = json_decode(file_get_contents('php://input'), true);
$appointmentId = (int)($body['appointment_id'] ?? 0);
$newStatus     = trim($body['status'] ?? '');

$result = updateAppointmentStatus($appointmentId, $newStatus);

echo json_encode($result);