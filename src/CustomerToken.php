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
 * The customer token for a logged-in customer (api-contract.md §7.2): a short-lived JWT signed with
 * the connection key (EdDSA). ChatPuff accepts each one once, so the module creates a new one for
 * every page view and never stores it.
 */
final class CustomerToken
{
    /** The longest lifetime ChatPuff accepts. */
    public const LIFETIME = 600;

    /**
     * @param array<string, mixed> $connection key_id and secret_key, as Settings::connection() returns them
     */
    public static function create(array $connection, string $installationId, string $shopContext, string $customerId, string $name, string $email, int $now): string
    {
        $header = ['alg' => 'EdDSA', 'kid' => (string) $connection['key_id'], 'typ' => 'JWT'];
        $claims = [
            'iss' => $installationId,
            'aud' => 'chatpuff-widget',
            'ctx' => $shopContext,
            'sub' => $customerId,
            'name' => $name,
            'email' => $email,
            'iat' => $now,
            'exp' => $now + self::LIFETIME,
            'jti' => bin2hex(random_bytes(16)),
        ];
        $unsigned = self::encode((string) json_encode($header)) . '.' . self::encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = (string) base64_decode(Crypto::sign((string) $connection['secret_key'], $unsigned), true);

        return $unsigned . '.' . self::encode($signature);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
