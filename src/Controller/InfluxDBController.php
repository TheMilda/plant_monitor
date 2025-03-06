<?php

namespace App\Controller;

use App\Service\InfluxDBService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class InfluxDBController extends AbstractController
{
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
}
