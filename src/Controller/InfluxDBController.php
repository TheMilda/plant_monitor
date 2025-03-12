<?php

namespace App\Controller;

use App\Service\InfluxDBService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class InfluxDBController extends AbstractController
{
    /**
     * Get the latest data from InfluxDB
     */
    #[Route('/influxdb/latest', name: 'get_latest_influxdb_data', methods: ['GET'])]
    public function getLatestData(InfluxDBService $influxDBService): JsonResponse
    {
        try {
            $data = $influxDBService->getLatestData();
            return $this->json($data);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get historical data from InfluxDB
     */
    #[Route('/influxdb/history', name: 'get_historical_data', methods: ['GET'])]
    public function getHistoricalData(InfluxDBService $influxDBService): JsonResponse
    {
        try {
            $data = $influxDBService->getHistoricalData();
            return $this->json($data);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}