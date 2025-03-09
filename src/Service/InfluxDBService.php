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
            |> range(start: -30d) 
            |> filter(fn: (r) => r["_measurement"] == "temperature" or 
                                 r["_measurement"] == "humidity" or 
                                 r["_measurement"] == "pressure" or 
                                 r["_measurement"] == "luminance" or 
                                 r["_measurement"] == "moisture_a" or 
                                 r["_measurement"] == "moisture_b" or 
                                 r["_measurement"] == "moisture_c")
            |> group(columns: ["_measurement"])  
            |> sort(columns: ["_time"], desc: true)  
            |> limit(n:1)  
        EOT;

        $url = "{$this->influxdbUrl}/api/v2/query?org=" . urlencode($this->influxdbOrg);

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => "Token {$this->influxdbToken}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/csv',
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

            // ✅ Check if the response contains data
            if (empty($csvData)) {
                throw new \Exception("⚠️ InfluxDB returned an empty response!");
            }

            // ✅ Ensure debug file is created in /tmp/
            $debugPath = "/tmp/debug_influx_fixed.csv";
            if (!file_put_contents($debugPath, $csvData)) {
                error_log("❌ Failed to write debug file: $debugPath");
            } else {
                error_log("✅ InfluxDB response saved to: $debugPath");
            }

            return $this->parseInfluxDBCsv($csvData);

        } catch (\Exception $e) {
            // ❌ Log errors if request fails
            error_log("❌ InfluxDB error: " . $e->getMessage());
            return [];
        }
    }

    private function parseInfluxDBCsv(string $csvData): array
    {
        $lines = explode("\n", trim($csvData));
        if (count($lines) < 3) {
            throw new \Exception("InfluxDB returned unexpected CSV format.");
        }

        // ✅ Fix deprecated `str_getcsv()` escape parameter
        $header = str_getcsv($lines[1], ",", '"', "\\");

        $data = [];

        foreach (array_slice($lines, 2) as $line) { // Process from the third row onward
            $row = str_getcsv($line, ",", '"', "\\");
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

    private function fetchInfluxDBData(string $query): array
    {
        $url = "{$this->influxdbUrl}/api/v2/query?org=" . urlencode($this->influxdbOrg);

        $response = $this->client->post($url, [
            'headers' => [
                'Authorization' => "Token {$this->influxdbToken}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/csv',
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
        
        // ✅ Save for debugging
        file_put_contents("/tmp/debug_history.csv", $csvData);

        return $this->parseInfluxDBCsv($csvData);
    }

    public function getHistoricalData(): array
    {
        $query = <<<EOT
            from(bucket: "{$this->influxdbBucket}")
                |> range(start: -30d) // Get last 30 days
                |> filter(fn: (r) => r["_measurement"] == "temperature" or 
                                     r["_measurement"] == "humidity" or 
                                     r["_measurement"] == "pressure" or 
                                     r["_measurement"] == "luminance" or 
                                     r["_measurement"] == "moisture_a" or 
                                     r["_measurement"] == "moisture_b" or 
                                     r["_measurement"] == "moisture_c")
                |> aggregateWindow(every: 1d, fn: mean, createEmpty: false) // ✅ Group by day
                |> sort(columns: ["_time"], desc: false)
        EOT;

        return $this->fetchInfluxDBData($query);
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
