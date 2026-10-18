<?php

namespace App\Tests\Service;

use App\Service\ClientNegotiation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Browsers and link-preview bots get file pages; tools and embeds get the file. Headers as seen in the wild. */
final class ClientNegotiationTest extends TestCase
{
    private const BROWSER_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    private static function request(?string $userAgent, ?string $accept): Request
    {
        $request = Request::create('/v/abc.png');
        $request->headers->remove('Accept');
        $request->headers->remove('User-Agent');
        if ($userAgent !== null) {
            $request->headers->set('User-Agent', $userAgent);
        }
        if ($accept !== null) {
            $request->headers->set('Accept', $accept);
        }
        return $request;
    }

    public function testBrowsersGetThePage(): void
    {
        self::assertTrue(ClientNegotiation::wantsPage(self::request('Mozilla/5.0 (X11; Linux x86_64; rv:157.0) Gecko/20100101 Firefox/157.0', self::BROWSER_ACCEPT)));
        self::assertTrue(ClientNegotiation::wantsPage(self::request('Mozilla/5.0 Chrome/140.0', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8')));
    }

    public function testPreviewBotsGetThePageWhateverTheyAccept(): void
    {
        self::assertTrue(ClientNegotiation::wantsPage(self::request('TelegramBot (like TwitterBot)', self::BROWSER_ACCEPT)));
        self::assertTrue(ClientNegotiation::wantsPage(self::request('Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)', self::BROWSER_ACCEPT)));
        self::assertTrue(ClientNegotiation::wantsPage(self::request('continuwuity/26.9.1+046074b (embedbot; facebookexternalhit/1.1; +https://continuwuity.org)', '*/*')));
        self::assertTrue(ClientNegotiation::wantsPage(self::request('Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', null)));
    }

    public function testToolsAndEmbedsGetTheFile(): void
    {
        self::assertFalse(ClientNegotiation::wantsPage(self::request('curl/8.11.1', '*/*')));
        self::assertFalse(ClientNegotiation::wantsPage(self::request('Wget/1.25.0', '*/*')));
        self::assertFalse(ClientNegotiation::wantsPage(self::request('HTTPie/3.2.4', 'application/json, */*;q=0.5')));
        self::assertFalse(ClientNegotiation::wantsPage(self::request('Go-http-client/1.1', null)));
        self::assertFalse(ClientNegotiation::wantsPage(self::request(null, null)));
        self::assertFalse(ClientNegotiation::wantsPage(self::request('Mozilla/5.0 Firefox/157.0', 'image/avif,image/webp,image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5')), '<img> embed');
        self::assertFalse(ClientNegotiation::wantsPage(self::request('Mozilla/5.0 Firefox/157.0', 'text/html;q=0, */*')), 'HTML explicitly refused');
    }

    public function testVaryAddsToExistingValues(): void
    {
        $response = new Response();
        $response->setVary('Accept-Encoding');
        self::assertSame(['Accept-Encoding', 'Accept', 'User-Agent'], ClientNegotiation::vary($response)->getVary());
    }
}
