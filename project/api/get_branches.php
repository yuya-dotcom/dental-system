<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../controllers/branch_controller.php';

echo json_encode(['branches' => getAllBranches()]);