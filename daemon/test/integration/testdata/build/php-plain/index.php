<?php

// What PHP knows about the request scheme (docs/04 §3.2).
header('Content-Type: application/json');
echo json_encode([
    'set' => isset($_SERVER['HTTPS']),
    'https' => $_SERVER['HTTPS'] ?? null,
]);
