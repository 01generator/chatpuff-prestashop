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
 * ChatPuff asks about an order so that a customer can prove it is theirs (api-contract.md §7.7).
 * Only ChatPuff may ask: the call is signed with ChatPuff's own key, which the module received when
 * it connected, within five minutes, and never twice. The answer names the order, its customer and
 * the email address on it, nothing more.
 */
final class OrderCallback
{
    public const CAPABILITY = 'order_verification';
    public const SCHEME = 'CHATPUFF-SAAS-ED25519-V1';
    public const NONCES = 'CHATPUFF_SAAS_NONCES';
    private const CLOCK_WINDOW = 300;
    private const NONCE_LIFETIME = 600;
    private const NONCE_LIMIT = 500;

    /** @var ApiClient */
    private $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * @param array<string, string> $headers Chatpuff-Key-Id, Chatpuff-Timestamp, Chatpuff-Nonce, Chatpuff-Signature
     * @param string $pathAndQuery the request target exactly as sent
     */
    public function isSignedByChatPuff(int $idShop, array $headers, string $method, string $pathAndQuery): bool
    {
        $connection = Settings::connection($idShop);
        $keyId = $headers['Chatpuff-Key-Id'] ?? '';
        $timestamp = $headers['Chatpuff-Timestamp'] ?? '';
        $nonce = $headers['Chatpuff-Nonce'] ?? '';
        $signature = $headers['Chatpuff-Signature'] ?? '';
        if ($connection === null || $keyId === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1 || $signature === '') {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::CLOCK_WINDOW) {
            return false;
        }
        $publicKey = $this->publicKey($idShop, $connection, $keyId);
        $message = implode("\n", [self::SCHEME, strtoupper($method), $pathAndQuery, $timestamp, $nonce, hash('sha256', '')]);
        if ($publicKey === null || !Crypto::verify($publicKey, $message, $signature)) {
            return false;
        }

        return self::firstUse($nonce);
    }

    /**
     * One of this shop's orders by the reference its customer sees, or by its ID when only digits
     * were typed.
     *
     * @return array{id: string, reference: string, customer_id: string|null, email: string, language: string|null}|null
     */
    public function findOrder(int $idShop, string $reference): ?array
    {
        $idOrder = (int) \Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = \'' . pSQL($reference) . '\' AND `id_shop` = ' . (int) $idShop . ' ORDER BY `id_order` ASC'
        );
        if ($idOrder === 0 && ctype_digit($reference)) {
            $idOrder = (int) \Db::getInstance()->getValue(
                'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . (int) $reference . ' AND `id_shop` = ' . (int) $idShop
            );
        }
        if ($idOrder === 0) {
            return null;
        }
        $order = new \Order($idOrder);
        $customer = new \Customer((int) $order->id_customer);
        if (!\Validate::isLoadedObject($order) || !\Validate::isLoadedObject($customer) || !\Validate::isEmail($customer->email)) {
            return null;
        }
        $language = strtolower((string) \Language::getIsoById((int) $order->id_lang));

        return [
            'id' => (string) $order->id,
            'reference' => (string) $order->reference,
            // A guest checkout has a customer row, but nobody logs in as it: no customer token names it.
            'customer_id' => $customer->is_guest ? null : (string) $customer->id,
            'email' => (string) $customer->email,
            'language' => preg_match('/^[a-z]{2}$/', $language) === 1 ? $language : null,
        ];
    }

    /**
     * The public half of ChatPuff's key, from the keys saved at pairing; an unknown key ID makes the
     * module ask ChatPuff for its current keys once.
     *
     * @param array<string, mixed> $connection
     */
    private function publicKey(int $idShop, array $connection, string $keyId): ?string
    {
        $key = self::keyIn(is_array($connection['saas_keys'] ?? null) ? $connection['saas_keys'] : [], $keyId);
        if ($key !== null) {
            return $key;
        }
        try {
            $answer = $this->api->send('GET', '/integration/v1/saas-keys', null, (string) $connection['key_id'], (string) $connection['secret_key']);
        } catch (ApiException $exception) {
            return null;
        }
        $keys = is_array($answer['keys'] ?? null) ? $answer['keys'] : [];
        if ($keys !== []) {
            $connection['saas_keys'] = $keys;
            Settings::saveConnection($idShop, $connection);
        }

        return self::keyIn($keys, $keyId);
    }

    /**
     * @param array<mixed> $keys
     */
    private static function keyIn(array $keys, string $keyId): ?string
    {
        foreach ($keys as $key) {
            if (is_array($key) && ($key['key_id'] ?? null) === $keyId && is_string($key['public_key'] ?? null)) {
                return $key['public_key'];
            }
        }

        return null;
    }

    /**
     * Remembers the nonces of the last ten minutes, so that a recorded call cannot be sent again.
     */
    private static function firstUse(string $nonce): bool
    {
        $now = time();
        $seen = json_decode((string) \Configuration::getGlobalValue(self::NONCES), true);
        $seen = is_array($seen) ? $seen : [];
        $seen = array_filter($seen, static function ($at) use ($now): bool {
            return is_int($at) && $at > $now - self::NONCE_LIFETIME;
        });
        if (isset($seen[$nonce])) {
            return false;
        }
        $seen[$nonce] = $now;
        if (count($seen) > self::NONCE_LIMIT) {
            arsort($seen);
            $seen = array_slice($seen, 0, self::NONCE_LIMIT, true);
        }
        \Configuration::updateGlobalValue(self::NONCES, (string) json_encode($seen));

        return true;
    }
}
