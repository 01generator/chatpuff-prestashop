<?php

/**
 * ChatPuff live chat for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    ChatPuff <https://chatpuff.com>
 * @copyright 2026 ChatPuff
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace ChatPuff\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The shop domain as ChatPuff normalizes it: lowercase, IDNA ASCII, no scheme, port, path or trailing
 * dot, and one leading "www." removed. It must match ChatPuff's own normalization exactly, because
 * the domain challenge signs it.
 */
final class ShopDomain
{
    public static function normalize(string $url): string
    {
        $host = strpos($url, '://') !== false ? (string) parse_url($url, PHP_URL_HOST) : (string) preg_replace('~[/?#:].*$~', '', trim($url));
        $host = rtrim(strtolower($host), '.');
        if ($host !== '' && preg_match('/[^\x20-\x7e]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            $host = $ascii === false ? $host : $ascii;
        }
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
