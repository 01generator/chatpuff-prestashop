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
 * The module's stored state. The installation ID is shared by all shops of this PrestaShop; the
 * connection and a pairing in progress are stored per shop, with private keys encrypted.
 */
final class Settings
{
    public const INSTALLATION_ID = 'CHATPUFF_INSTALLATION_ID';
    public const CONNECTION = 'CHATPUFF_CONNECTION';
    public const PAIRING = 'CHATPUFF_PAIRING';
    public const REPORTED_AT = 'CHATPUFF_REPORTED_AT';
    public const PRIVACY_CMS = 'CHATPUFF_PRIVACY_CMS';

    /** The installation report doubles as the connection's heartbeat: at most once an hour (api-contract.md §7). */
    public const REPORT_INTERVAL = 3600;

    public static function installationId(): string
    {
        $id = (string) \Configuration::getGlobalValue(self::INSTALLATION_ID);
        if (preg_match('/^[0-9a-f-]{36}$/', $id) !== 1) {
            $id = self::resetInstallationId();
        }

        return $id;
    }

    /**
     * A new identity for this PrestaShop, for example on a copy of a live shop. Existing
     * connections keep working; the next pairing counts as a new installation.
     */
    public static function resetInstallationId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);
        $id = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        \Configuration::updateGlobalValue(self::INSTALLATION_ID, $id);

        return $id;
    }

    /**
     * @return array<string, mixed>|null key_id, shop_id, domain, secret_key (decrypted), saas_keys, dashboard_url
     */
    public static function connection(int $idShop): ?array
    {
        $data = self::read(self::CONNECTION, $idShop);
        if ($data === null || !is_string($data['secret_key'] ?? null)) {
            return null;
        }
        $secret = Crypto::decrypt($data['secret_key']);
        if ($secret === null) {
            return null;
        }
        $data['secret_key'] = $secret;

        return $data;
    }

    /**
     * What the storefront needs on every page: the ChatPuff shop ID and the domain the shop was
     * connected with. Unlike connection(), it does not decrypt the private key.
     *
     * @return array{shop_id: string, domain: string}|null
     */
    public static function widget(int $idShop): ?array
    {
        $data = self::read(self::CONNECTION, $idShop);
        if ($data === null || !is_string($data['shop_id'] ?? null) || $data['shop_id'] === '' || !is_string($data['domain'] ?? null)) {
            return null;
        }

        return ['shop_id' => $data['shop_id'], 'domain' => $data['domain']];
    }

    /**
     * @param array<string, mixed> $connection with the private key in plain text
     */
    public static function saveConnection(int $idShop, array $connection): void
    {
        $connection['secret_key'] = Crypto::encrypt((string) $connection['secret_key']);
        self::write(self::CONNECTION, $idShop, $connection);
    }

    public static function clearConnection(int $idShop): void
    {
        self::write(self::CONNECTION, $idShop, null);
    }

    /**
     * @return array<string, mixed>|null request_id, public_key, secret_key (decrypted), domain, confirmation_url, expires_at, outcome, code
     */
    public static function pairing(int $idShop): ?array
    {
        $data = self::read(self::PAIRING, $idShop);
        if ($data === null) {
            return null;
        }
        if (is_string($data['secret_key'] ?? null)) {
            $data['secret_key'] = Crypto::decrypt($data['secret_key']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $pairing with the private key in plain text
     */
    public static function savePairing(int $idShop, array $pairing): void
    {
        if (is_string($pairing['secret_key'] ?? null)) {
            $pairing['secret_key'] = Crypto::encrypt($pairing['secret_key']);
        }
        self::write(self::PAIRING, $idShop, $pairing);
    }

    public static function clearPairing(int $idShop): void
    {
        self::write(self::PAIRING, $idShop, null);
    }

    /**
     * Whether this shop's hourly report is due. Claiming it at once keeps the other visitors of the
     * same moment from sending it too.
     */
    public static function claimReport(int $idShop, int $now): bool
    {
        $last = (int) \Configuration::get(self::REPORTED_AT, null, null, $idShop);
        if ($now - $last < self::REPORT_INTERVAL) {
            return false;
        }
        \Configuration::updateValue(self::REPORTED_AT, $now, false, (int) \Shop::getGroupFromShop($idShop, true), $idShop);

        return true;
    }

    /**
     * The CMS page the chat links to where it asks for a name and email (api-contract.md §7.1), or 0.
     */
    public static function privacyPage(int $idShop): int
    {
        return (int) \Configuration::get(self::PRIVACY_CMS, null, null, $idShop);
    }

    public static function savePrivacyPage(int $idShop, int $idCms): void
    {
        \Configuration::updateValue(self::PRIVACY_CMS, $idCms, false, (int) \Shop::getGroupFromShop($idShop, true), $idShop);
    }

    public static function deleteAll(): void
    {
        foreach ([self::INSTALLATION_ID, self::CONNECTION, self::PAIRING, self::REPORTED_AT, self::PRIVACY_CMS] as $key) {
            \Configuration::deleteByName($key);
        }
    }

    /**
     * @return list<int> the shops that have a connection
     */
    public static function connectedShopIds(): array
    {
        $ids = [];
        foreach (\Shop::getShops(false, null, true) as $idShop) {
            if (self::read(self::CONNECTION, (int) $idShop) !== null) {
                $ids[] = (int) $idShop;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read(string $key, int $idShop): ?array
    {
        $raw = \Configuration::get($key, null, null, $idShop);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    private static function write(string $key, int $idShop, ?array $value): void
    {
        $idShopGroup = (int) \Shop::getGroupFromShop($idShop, true);
        \Configuration::updateValue($key, $value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_SLASHES), false, $idShopGroup, $idShop);
    }
}
