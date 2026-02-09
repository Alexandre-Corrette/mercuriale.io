<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Redirect to login or admin based on authentication
        if ($this->getUser()) {
            return $this->redirectToRoute('admin');
        }

        return $this->redirectToRoute('app_login');
    }
}
