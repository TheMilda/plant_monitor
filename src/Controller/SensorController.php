<?php

namespace App\Controller; // ✅ Ensure this matches exactly

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SensorController extends AbstractController
{
    #[Route('/charts/moisture/{pot}', name: 'chart')]
    public function chartPage(string $pot): Response
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

        return $this->render('sensor/chart.html.twig', [
            'pot' => $pot,
            'measurement' => $measurementMap[$pot]
        ]);
    }
}
