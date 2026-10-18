<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who opened a file page (/v/, /u/): a browser, which gets the page, or a tool or embed, which
 * gets the file itself. Browsers say so in Accept (text/html, also when a service worker forwards
 * the navigation); curl, wget, scripts and <img>/<video> tags do not. Link-preview bots get the
 * page for its OpenGraph tags, matched by User-Agent because some send a wildcard Accept.
 */
final class ClientNegotiation
{
    private const PREVIEW_BOTS = '/discordbot|telegrambot|twitterbot|facebookexternalhit|facebot|slackbot|whatsapp|linkedinbot'
        . '|mastodon|bluesky|cardyb|skypeuripreview|redditbot|embedly|iframely|synapse|vkshare|pinterest/i';

    private const PAGE_TYPES = ['text/html', 'application/xhtml+xml'];

    public static function wantsPage(Request $request): bool
    {
        if (preg_match(self::PREVIEW_BOTS, (string) $request->headers->get('User-Agent'))) {
            return true;
        }
        foreach (AcceptHeader::fromString($request->headers->get('Accept'))->all() as $item) {
            if (in_array(strtolower($item->getValue()), self::PAGE_TYPES, true) && $item->getQuality() > 0) {
                return true;
            }
        }
        return false;
    }

    /** The answer depends on these request headers, so caches must keep one copy per value. */
    public static function vary(Response $response): Response
    {
        $response->setVary(['Accept', 'User-Agent'], false);
        return $response;
    }
}
