<?php

namespace App\Controller;

use App\Service\InfluxDBService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    /**
     * Main dashboard page displaying environmental data and pot moisture levels
     */
    #[Route('/dashboard', name: 'dashboard')]
    #[Route('/', name: 'home')]
    public function index(InfluxDBService $influxDBService): Response
    {
        // Pre-fetch latest data to avoid loading delays when returning from charts
        try {
            $latestData = $influxDBService->getLatestData();
            
            // Process data for the template
            $processedData = [];
            foreach ($latestData as $item) {
                $processedData[$item['measurement']] = [
                    'value' => $item['value'],
                    'time' => $item['time']
                ];
            }
            
        } catch (\Exception $e) {
            // Log the error but continue rendering the page
            $processedData = [];
        }

        return $this->render('dashboard.html.twig', [
            'latestData' => $processedData
        ]);
    }
}