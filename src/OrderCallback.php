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
 * ChatPuff asks about an order so that a customer can prove it is theirs, and then for what may be
 * told about it (api-contract.md §7.7). Only ChatPuff may ask: the call is signed with ChatPuff's
 * own key, which the module received when it connected, within five minutes, and never twice. The
 * check names the order, its customer and the email address on it; the details its status, items
 * and tracking, nothing more.
 */
final class OrderCallback
{
    public const CAPABILITY = 'order_verification';
    public const DETAILS_CAPABILITY = 'order_details';
    /** A stand-in order ID, replaced by {id} in the back office's order address. */
    private const SAMPLE_ORDER_ID = 987654321;
    private const MAX_ITEMS = 50;
    private const MAX_SHIPMENTS = 10;
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
     * What may be told about one of this shop's orders, by its ID (api-contract.md §7.7: the date
     * placed, the status, the items and the tracking; no prices, addresses or payment details).
     *
     * @return array<string, mixed>|null
     */
    public function describeOrder(int $idShop, string $orderId): ?array
    {
        if (preg_match('/^[1-9]\d{0,9}$/', $orderId) !== 1) {
            return null;
        }
        $order = new \Order((int) $orderId);
        if (!\Validate::isLoadedObject($order) || (int) $order->id_shop !== $idShop) {
            return null;
        }
        $state = new \OrderState((int) $order->current_state, (int) $order->id_lang);
        $status = self::status($order, $state);
        $label = \Validate::isLoadedObject($state) ? trim((string) $state->name) : '';

        return [
            'id' => (string) $order->id,
            'reference' => (string) $order->reference,
            'placed_at' => date(DATE_ATOM, (int) strtotime((string) $order->date_add)),
            'status' => $status,
            'status_label' => $label !== '' ? (string) \Tools::substr($label, 0, 100) : ucfirst($status),
            'items' => self::items((int) $order->id),
            'tracking' => self::tracking($order),
        ];
    }

    /**
     * The address of an order's page in this back office, with {id} for the order ID: the inbox
     * links a verified order there, and PrestaShop checks the employee's own permissions.
     */
    public static function adminOrderUrl(\Link $link): string
    {
        try {
            $url = (string) $link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => self::SAMPLE_ORDER_ID], ['id_order' => self::SAMPLE_ORDER_ID, 'vieworder' => 1]);
        } catch (\Exception $exception) {
            return '';
        }

        return strpos($url, (string) self::SAMPLE_ORDER_ID) === false ? '' : str_replace((string) self::SAMPLE_ORDER_ID, '{id}', $url);
    }

    /**
     * The order's state as ChatPuff names it, from the shop's configured states and the state's flags.
     */
    private static function status(\Order $order, \OrderState $state): string
    {
        $id = (int) $order->current_state;
        $is = static function (string $key) use ($id): bool {
            $configured = (int) \Configuration::get($key);

            return $configured > 0 && $configured === $id;
        };
        if ($is('PS_OS_REFUND')) {
            return 'refunded';
        }
        if ($is('PS_OS_CANCELED')) {
            return 'cancelled';
        }
        if (!\Validate::isLoadedObject($state)) {
            return 'pending';
        }
        if ($state->delivered || $is('PS_OS_DELIVERED')) {
            return 'delivered';
        }
        if ($state->shipped || $is('PS_OS_SHIPPING')) {
            return 'shipped';
        }

        return $state->paid || $state->logable ? 'processing' : 'pending';
    }

    /**
     * @return list<array{name: string, quantity: int}>
     */
    private static function items(int $idOrder): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `product_name`, `product_quantity` FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order` = ' . $idOrder . ' ORDER BY `id_order_detail` ASC LIMIT ' . self::MAX_ITEMS
        );
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = trim((string) $row['product_name']);
            $quantity = (int) $row['product_quantity'];
            if ($name !== '' && $quantity > 0) {
                $items[] = ['name' => (string) \Tools::substr($name, 0, 255), 'quantity' => $quantity];
            }
        }

        return $items;
    }

    /**
     * The shipments that have a tracking number, with the carrier's tracking address (its @ is the number).
     *
     * @return list<array{carrier: string|null, number: string, url: string|null}>
     */
    private static function tracking(\Order $order): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT oc.`tracking_number`, c.`name`, c.`url` FROM `' . _DB_PREFIX_ . 'order_carrier` oc'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'carrier` c ON c.`id_carrier` = oc.`id_carrier`'
            . ' WHERE oc.`id_order` = ' . (int) $order->id . ' AND oc.`tracking_number` <> \'\' ORDER BY oc.`id_order_carrier` ASC LIMIT ' . self::MAX_SHIPMENTS
        );
        $shipments = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $number = trim((string) $row['tracking_number']);
            if ($number === '' || \Tools::strlen($number) > 100) {
                continue;
            }
            // A carrier named "0" carries the shop's own name in PrestaShop.
            $carrier = (string) $row['name'] === '0' ? (string) \Configuration::get('PS_SHOP_NAME', null, null, (int) $order->id_shop) : trim((string) $row['name']);
            $url = str_replace('@', rawurlencode($number), trim((string) $row['url']));
            $shipments[] = [
                'carrier' => $carrier !== '' ? (string) \Tools::substr($carrier, 0, 100) : null,
                'number' => $number,
                'url' => preg_match('#^https?://\S+$#', $url) === 1 && strlen($url) <= 500 ? $url : null,
            ];
        }

        return $shipments;
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
