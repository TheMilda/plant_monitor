<?php

namespace App\Service;

use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Log\LoggerInterface;

class InfluxDBService
{
    private string $influxdbUrl;
    private string $influxdbToken;
    private string $influxdbOrg;
    private string $influxdbBucket;
    private Client $client;
    private FilesystemAdapter $cache;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger = null)
    {
        $this->influxdbUrl = $_SERVER["INFLUXDB_URL"] ?? '';
        $this->influxdbToken = $_SERVER["INFLUXDB_TOKEN"] ?? '';
        $this->influxdbOrg = $_SERVER["INFLUXDB_ORG"] ?? '';
        $this->influxdbBucket = $_SERVER["INFLUXDB_BUCKET"] ?? '';
        $this->client = new Client();
        $this->cache = new FilesystemAdapter('influxdb_cache', 300);
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }

    /**
     * Get the latest sensor data with caching to improve performance
     */
    public function getLatestData(): array
    {
        // Check cache first
        $cacheKey = 'latest_data';
        $cachedItem = $this->cache->getItem($cacheKey);
        
        if ($cachedItem->isHit()) {
            return $cachedItem->get();
        }
        
        $query = <<<EOT
        from(bucket: "{$this->influxdbBucket}")
            |> range(start: 2025-03-08T00:00:00Z) 
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

        try {
            $data = $this->fetchInfluxDBData($query);
            
            // Make sure all measurements are represented
            $expectedMeasurements = [
                'temperature',
                'humidity',
                'pressure',
                'luminance',
                'moisture_a',
                'moisture_b',
                'moisture_c'
            ];
            
            // Check if any measurements are missing
            $existingMeasurements = array_column($data, 'measurement');
            $missingMeasurements = array_diff($expectedMeasurements, $existingMeasurements);
            
            // Check for potentially incorrect values (like 0.0 for luminance during daytime)
            foreach ($data as $index => $item) {
                if ($item['measurement'] === 'luminance') {
                    $hour = (int)date('H');
                    $isDaytime = ($hour >= 7 && $hour <= 19);
                    
                    // If it's daytime and luminance is suspiciously low, flag it for replacement
                    if ($isDaytime && $item['value'] < 0.5) {
                        $this->logger->info('Suspicious luminance value during daytime: ' . $item['value']);
                        if (!in_array('luminance', $missingMeasurements)) {
                            $missingMeasurements[] = 'luminance';
                        }
                        // Remove the suspicious data
                        unset($data[$index]);
                    }
                }
            }
            
            // Reindex array after potential deletions
            $data = array_values($data);
            
            // If we have missing measurements, add fallback data from cache if available
            if (!empty($missingMeasurements)) {
                $this->logger->info('Missing measurements: ' . implode(', ', $missingMeasurements));
                
                // Try to get previous data from cache
                $previousDataItem = $this->cache->getItem('previous_latest_data');
                if ($previousDataItem->isHit()) {
                    $previousData = $previousDataItem->get();
                    
                    // Add missing measurements from previous data
                    foreach ($missingMeasurements as $missingMeasurement) {
                        foreach ($previousData as $previous) {
                            if ($previous['measurement'] === $missingMeasurement) {
                                $data[] = $previous;
                                $this->logger->info("Using previous data for {$missingMeasurement}");
                                break;
                            }
                        }
                    }
                }
            }
            
            // Cache the results for 60 seconds
            $cachedItem->set($data);
            $cachedItem->expiresAfter(60);
            $this->cache->save($cachedItem);
            
            // Also store as previous data with longer expiry
            $previousDataItem = $this->cache->getItem('previous_latest_data');
            $previousDataItem->set($data);
            $previousDataItem->expiresAfter(3600); // Keep previous data for an hour
            $this->cache->save($previousDataItem);
            
            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching latest data: ' . $e->getMessage());
            
            // Try to return cached data even if it's expired
            $previousDataItem = $this->cache->getItem('previous_latest_data');
            if ($previousDataItem->isHit()) {
                return $previousDataItem->get();
            }
            
            return [];
        }
    }

    /**
     * Get historical data with caching to improve performance
     */
    public function getHistoricalData(int $days = 30): array
    {
        // Check cache first
        $cacheKey = 'historical_data_' . $days;
        $cachedItem = $this->cache->getItem($cacheKey);
        
        if ($cachedItem->isHit()) {
            return $cachedItem->get();
        }
        
        $query = <<<EOT
            from(bucket: "{$this->influxdbBucket}")
                |> range(start: 2025-03-08T00:00:00Z) 
                |> filter(fn: (r) => r["_measurement"] == "temperature" or 
                                     r["_measurement"] == "humidity" or 
                                     r["_measurement"] == "pressure" or 
                                     r["_measurement"] == "luminance" or 
                                     r["_measurement"] == "moisture_a" or 
                                     r["_measurement"] == "moisture_b" or 
                                     r["_measurement"] == "moisture_c")
                |> aggregateWindow(every: 1d, fn: mean, createEmpty: false)
                |> sort(columns: ["_time"], desc: false)
        EOT;

        try {
            $data = $this->fetchInfluxDBData($query);
            
            // Cache the results for 5 minutes
            $cachedItem->set($data);
            $cachedItem->expiresAfter(300);
            $this->cache->save($cachedItem);
            
            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching historical data: ' . $e->getMessage());
            
            // Try to return cached data even if it's expired
            $previousHistoryItem = $this->cache->getItem('previous_historical_data');
            if ($previousHistoryItem->isHit()) {
                return $previousHistoryItem->get();
            }
            
            return [];
        }
    }

    /**
     * Execute an InfluxDB query and parse the results
     */
    private function fetchInfluxDBData(string $query): array
    {
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
                'timeout' => 10,
                'connect_timeout' => 5
            ]);

            $csvData = $response->getBody()->getContents();
            
            if (empty($csvData)) {
                throw new \Exception("InfluxDB returned an empty response");
            }
            
            return $this->parseInfluxDBCsv($csvData);
        } catch (\Exception $e) {
            $this->logger->error('Error in InfluxDB query: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Parse CSV data from InfluxDB into a structured array
     */
    private function parseInfluxDBCsv(string $csvData): array
    {
        $lines = explode("\n", trim($csvData));
        if (count($lines) < 3) {
            throw new \Exception("InfluxDB returned unexpected CSV format");
        }

        $header = str_getcsv($lines[1], ",", '"', "\\");

        $data = [];

        foreach (array_slice($lines, 2) as $line) {
            if (empty(trim($line))) continue;
            
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
                'device' => $entry['device'] ?? 'unknown'
            ];
        }

        return $data;
    }
}