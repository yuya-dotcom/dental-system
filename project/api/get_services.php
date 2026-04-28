<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../controllers/appointment_controller.php';

$services = getServices();

echo json_encode(['services' => $services]);