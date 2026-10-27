<?php

namespace App\Enums;

enum ScrapingMethod: string
{
    case Playwright = 'playwright';
    case Puppeteer = 'puppeteer';

    public function label(): string
    {
        return match ($this) {
            self::Playwright => 'Playwright',
            self::Puppeteer => 'Puppeteer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Playwright => 'Microsoft\'s browser automation library. Fast and reliable, and the default.',
            self::Puppeteer => 'Google\'s browser automation library for Chrome. A good alternative if a site works better with it.',
        };
    }
}
