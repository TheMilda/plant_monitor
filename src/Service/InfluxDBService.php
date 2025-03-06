<?php

namespace App\Service;

use GuzzleHttp\Client;

class InfluxDBService
{
    private string $influxdbUrl;
    private string $influxdbToken;
    private string $influxdbOrg;
    private string $influxdbBucket;
    private Client $client;

    public function __construct()
    {
        $this->influxdbUrl = $_SERVER["INFLUXDB_URL"];
        $this->influxdbToken = $_SERVER["INFLUXDB_TOKEN"];
        $this->influxdbOrg = $_SERVER["INFLUXDB_ORG"];
        $this->influxdbBucket = $_SERVER["INFLUXDB_BUCKET"];
        $this->client = new Client();
    }

    public function getLatestData(): array
{
    $query = <<<EOT
    from(bucket: "{$this->influxdbBucket}")
        |> range(start: -1h)
        |> filter(fn: (r) => r["_measurement"] == "temperature" or 
                             r["_measurement"] == "humidity" or 
                             r["_measurement"] == "pressure" or 
                             r["_measurement"] == "luminance" or 
                             r["_measurement"] == "moisture_a" or 
                             r["_measurement"] == "moisture_b" or 
                             r["_measurement"] == "moisture_c")
        |> last()
    EOT;

    $url = "{$this->influxdbUrl}/api/v2/query?org=" . urlencode($this->influxdbOrg);

    $response = $this->client->post($url, [
        'headers' => [
            'Authorization' => "Token {$this->influxdbToken}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/csv', // Request CSV format
        ],
        'json' => [
            'query' => $query,
            'dialect' => [
                'header' => true,
                'delimiter' => ',',
                'annotations' => ['datatype'],
            ],
        ],
    ]);

    $csvData = $response->getBody()->getContents();
    file_put_contents(__DIR__ . "/debug_influx.csv", $csvData); // Save response for debugging

    return $this->parseInfluxDBCsv($csvData);
}

private function parseInfluxDBCsv(string $csvData): array
{
    $lines = explode("\n", trim($csvData));
    if (count($lines) < 3) {
        throw new \Exception("InfluxDB returned unexpected CSV format.");
    }

    // Skip first row (#datatype,...)
    $header = str_getcsv($lines[1]); // Use the second row as the real header
    $data = [];

    foreach (array_slice($lines, 2) as $line) { // Process from the third row onward
        $row = str_getcsv($line);
        if (count($row) < count($header)) {
            continue; // Skip incomplete rows
        }

        $entry = array_combine($header, $row);
        if (!$entry || !isset($entry['_time'], $entry['_value'], $entry['_measurement'])) {
            continue; // Skip if critical data is missing
        }

        $data[] = [
            'time' => $entry['_time'],
            'measurement' => $entry['_measurement'],
            'value' => (float) $entry['_value'],
            'device' => $entry['device'] ?? 'unknown' // Default to 'unknown' if missing
        ];
    }

    return $data;
}


    private function formatInfluxDBData(array $data): array
    {
        $result = [];
        foreach ($data['results'][0]['series'] ?? [] as $series) {
            foreach ($series['values'] as $value) {
                $result[$series['name']] = [
                    'time' => $value[0],
                    'value' => $value[1]
                ];
            }
        }
        return $result;
    }
}
