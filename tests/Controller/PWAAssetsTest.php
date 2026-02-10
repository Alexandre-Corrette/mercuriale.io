<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

class PWAAssetsTest extends TestCase
{
    private static function publicPath(string $relativePath): string
    {
        return dirname(__DIR__, 2) . '/public' . $relativePath;
    }

    public function testManifestJsonIsValid(): void
    {
        $path = self::publicPath('/manifest.json');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $json = json_decode($content, true);
        $this->assertNotNull($json, 'manifest.json is valid JSON');

        // Required PWA fields
        $this->assertArrayHasKey('name', $json);
        $this->assertArrayHasKey('short_name', $json);
        $this->assertArrayHasKey('start_url', $json);
        $this->assertArrayHasKey('display', $json);
        $this->assertArrayHasKey('icons', $json);

        // Sprint 6 additions
        $this->assertArrayHasKey('scope', $json);
        $this->assertArrayHasKey('id', $json);
        $this->assertArrayHasKey('screenshots', $json);
        $this->assertArrayHasKey('shortcuts', $json);
        $this->assertArrayHasKey('prefer_related_applications', $json);

        // Screenshots validation
        $this->assertCount(2, $json['screenshots']);
        $this->assertSame('wide', $json['screenshots'][0]['form_factor']);
        $this->assertSame('narrow', $json['screenshots'][1]['form_factor']);
    }

    public function testServiceWorkerServedCorrectly(): void
    {
        $path = self::publicPath('/sw.js');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('mercuriale-v1.5.0', $content);
        $this->assertStringContainsString('APP_SHELL_FILES', $content);
    }

    public function testOfflineHtmlHasNoInlineScripts(): void
    {
        $path = self::publicPath('/offline.html');
        $this->assertFileExists($path);

        $content = file_get_contents($path);

        // No inline onclick handlers
        $this->assertStringNotContainsString('onclick=', $content, 'offline.html must not contain inline onclick handlers');

        // No inline <script> blocks (only external <script src="..."> allowed)
        $scriptPattern = '/<script(?![^>]*\bsrc\b)[^>]*>/i';
        $this->assertDoesNotMatchRegularExpression($scriptPattern, $content, 'offline.html must not contain inline <script> tags');
    }

    public function testOfflineRetryJsExists(): void
    {
        $path = self::publicPath('/js/offline-retry.js');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('retry-btn', $content);
        $this->assertStringContainsString('indexedDB', $content);
    }
}
