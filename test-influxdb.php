<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load .env manually
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/.env');

// Check if variables are loaded
if (!isset($_SERVER["INFLUXDB_URL"]) || !isset($_SERVER["INFLUXDB_TOKEN"])) {
    die("❌ Environment variables are missing. Check your .env file.\n");
}

// Read environment variables
$influxdbUrl = $_SERVER["INFLUXDB_URL"] . "/api/v2/write";
$influxdbToken = $_SERVER["INFLUXDB_TOKEN"];
$influxdbOrg = $_SERVER["INFLUXDB_ORG"];
$influxdbBucket = $_SERVER["INFLUXDB_BUCKET"];

// Construct URL
$url = "{$influxdbUrl}?precision=s&org=" . urlencode($influxdbOrg) . "&bucket=" . urlencode($influxdbBucket);

// Test Payload
$payload = "temperature,device=test-sensor value=25.5 " . time();

$client = new \GuzzleHttp\Client();
try {
    $response = $client->post($url, [
        'headers' => [
            'Authorization' => "Token {$influxdbToken}",
            'Content-Type' => 'text/plain',
        ],
        'body' => $payload,
    ]);

    if ($response->getStatusCode() === 204) {
        echo "✅ Successfully connected to InfluxDB and wrote data.\n";
    } else {
        echo "⚠️ Unexpected response: " . $response->getStatusCode() . " - " . $response->getBody() . "\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
