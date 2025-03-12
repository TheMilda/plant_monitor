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

    public function __construct(RequestStack $requestStack, LoggerInterface $logger)
    {
        $this->influxdbUrl = $_SERVER["INFLUXDB_URL"] ?? '';
        $this->influxdbToken = $_SERVER["INFLUXDB_TOKEN"] ?? '';
        $this->influxdbOrg = $_SERVER["INFLUXDB_ORG"] ?? '';
        $this->influxdbBucket = $_SERVER["INFLUXDB_BUCKET"] ?? '';
        $this->client = new Client();
        $this->cache = new FilesystemAdapter('influxdb_cache', 300);
        $this->logger = $logger;
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

        try {
            $data = $this->fetchInfluxDBData($query);
            
            // Cache the results for 60 seconds
            $cachedItem->set($data);
            $cachedItem->expiresAfter(60);
            $this->cache->save($cachedItem);
            
            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching latest data: ' . $e->getMessage());
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
                |> range(start: -{$days}d) 
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