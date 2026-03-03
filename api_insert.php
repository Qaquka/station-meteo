<?php
// ===== CONFIG DB =====
$host = "localhost";
$user = "station";
$password = "Meteo_2026!Pi";
$dbname = "station_meteo";
$table = "meteo";

// ===== Sécurité simple (clé API) =====
$API_KEY = "c473565ff9f8e76a7b5a419f31ee7a0b";

$key = $_REQUEST["key"] ?? "";
if ($key !== $API_KEY) {
  http_response_code(401);
  echo "BAD KEY\n";
  exit;
}

// ===== Lecture paramètres =====
$temp = $_REQUEST["temperature"] ?? null;
$hum  = $_REQUEST["humidite"] ?? null;
$rain = $_REQUEST["pluie"] ?? 0;

if ($temp === null || $hum === null) {
  http_response_code(400);
  echo "MISSING DATA\n";
  exit;
}

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
  http_response_code(500);
  echo "DB ERROR\n";
  exit;
}
$conn->set_charset("utf8mb4");

// INSERT (sans pression/vent)
$stmt = $conn->prepare("INSERT INTO `$table` (temperature, humidite, pluie, date_mesure) VALUES (?, ?, ?, NOW())");
$stmt->bind_param("ddd", $temp, $hum, $rain);
$stmt->execute();

echo "OK\n";
