<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-pwa-screenshots',
    description: 'Generate placeholder PWA screenshots for manifest.json',
)]
class GeneratePwaScreenshotsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $iconsDir = dirname(__DIR__, 2) . '/public/icons';

        if (!is_dir($iconsDir)) {
            mkdir($iconsDir, 0755, true);
        }

        $this->generateScreenshot(
            $iconsDir . '/screenshot-wide.png',
            1280,
            720,
            'Mercuriale.io — Tableau de bord',
            $io,
        );

        $this->generateScreenshot(
            $iconsDir . '/screenshot-narrow.png',
            750,
            1334,
            'Mercuriale.io — Scanner BL',
            $io,
        );

        $io->success('PWA screenshots generated in public/icons/');

        return Command::SUCCESS;
    }

    private function generateScreenshot(string $path, int $width, int $height, string $label, SymfonyStyle $io): void
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            $io->error("Failed to create image: $path");
            return;
        }

        // Background: dark navy (#1B2A4A)
        $bg = imagecolorallocate($image, 0x1B, 0x2A, 0x4A);
        imagefill($image, 0, 0, $bg);

        // Accent bar at top: green (#2E7D32)
        $green = imagecolorallocate($image, 0x2E, 0x7D, 0x32);
        imagefilledrectangle($image, 0, 0, $width, 60, $green);

        // White text
        $white = imagecolorallocate($image, 255, 255, 255);
        $gray = imagecolorallocate($image, 160, 170, 190);

        // App name in accent bar
        imagestring($image, 5, 20, 22, 'Mercuriale.io', $white);

        // Centered label
        $labelX = (int) (($width - strlen($label) * imagefontwidth(5)) / 2);
        $labelY = (int) ($height / 2 - 10);
        imagestring($image, 5, $labelX, $labelY, $label, $white);

        // Subtitle
        $sub = 'Placeholder — run Lighthouse audit';
        $subX = (int) (($width - strlen($sub) * imagefontwidth(3)) / 2);
        imagestring($image, 3, $subX, $labelY + 30, $sub, $gray);

        // Card placeholders
        $cardColor = imagecolorallocate($image, 0x25, 0x3A, 0x5E);
        $cardY = (int) ($height * 0.65);
        for ($i = 0; $i < 3; $i++) {
            $cx = (int) ($width * 0.1);
            $cw = (int) ($width * 0.8);
            $cy = $cardY + $i * 70;
            imagefilledrectangle($image, $cx, $cy, $cx + $cw, $cy + 55, $cardColor);
        }

        imagepng($image, $path);
        imagedestroy($image);

        $io->writeln("  Generated: $path ({$width}x{$height})");
    }
}
