<?php

namespace App\Controller;

use App\Service\InfluxDBService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SensorController extends AbstractController
{
    /**
     * Display moisture chart for a specific pot
     * 
     * @param string $pot Pot identifier (A, B, or C)
     */
    #[Route('/charts/moisture/{pot}', name: 'chart')]
    public function chartPage(string $pot, InfluxDBService $influxDBService): Response
    {
        // Convert Pot A, B, C to measurement names
        $measurementMap = [
            'A' => 'moisture_a',
            'B' => 'moisture_b',
            'C' => 'moisture_c'
        ];

        if (!isset($measurementMap[$pot])) {
            throw $this->createNotFoundException('Invalid pot selection');
        }

        // Pre-fetch the latest data for this pot to avoid loading delays
        try {
            $latestData = $influxDBService->getLatestData();
            $potData = array_filter($latestData, function($item) use ($measurementMap, $pot) {
                return $item['measurement'] === $measurementMap[$pot];
            });
            
            $currentValue = !empty($potData) ? reset($potData)['value'] : null;
        } catch (\Exception $e) {
            // Log the error but continue rendering the page
            $currentValue = null;
        }

        return $this->render('sensor/chart.html.twig', [
            'pot' => $pot,
            'measurement' => $measurementMap[$pot],
            'currentValue' => $currentValue
        ]);
    }
}