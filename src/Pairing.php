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
 * Connects one shop of this PrestaShop to ChatPuff (api-contract.md §6): the key pair is created
 * here and its private half never leaves the shop. ChatPuff then checks the shop's domain with a
 * challenge that only this module can sign.
 */
final class Pairing
{
    /** @var ApiClient */
    private $api;

    /** @var \Link */
    private $link;

    public function __construct(ApiClient $api, \Link $link)
    {
        $this->api = $api;
        $this->link = $link;
    }

    /**
     * @return string the owner's confirmation link
     *
     * @throws ApiException
     */
    public function start(int $idShop): string
    {
        $shop = new \Shop($idShop);
        $storefront = $shop->getBaseURL(true);
        $keys = Crypto::generateKeyPair();
        // Documented as a string, but false for an unknown language.
        $language = (string) \Language::getIsoById((int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop));

        $response = $this->api->send('POST', '/integration/v1/pairing-requests', [
            'platform_type' => 'prestashop',
            'installation_identifier' => Settings::installationId(),
            'external_shop_context' => (string) $idShop,
            'storefront_url' => $storefront,
            'callback_base_url' => $this->link->getModuleLink('chatpuff', 'callback', [], true, null, $idShop),
            'public_key' => $keys['public'],
            'shop_name' => (string) \Configuration::get('PS_SHOP_NAME', null, null, $idShop),
            'default_locale' => $language !== '' ? $language : 'en',
            'timezone' => (string) \Configuration::get('PS_TIMEZONE', null, null, $idShop),
            'metadata' => self::metadata(),
        ], ApiClient::PAIRING_KEY_ID, $keys['secret']);

        Settings::savePairing($idShop, [
            'request_id' => (string) ($response['pairing_request_id'] ?? ''),
            'public_key' => $keys['public'],
            'secret_key' => $keys['secret'],
            'domain' => ShopDomain::normalize($storefront),
            'confirmation_url' => (string) ($response['confirmation_url'] ?? ''),
            'expires_at' => (string) ($response['expires_at'] ?? ''),
            'outcome' => 'pending',
        ]);

        return (string) ($response['confirmation_url'] ?? '');
    }

    /**
     * Asks ChatPuff how the pairing is going and stores the connection once it is complete.
     *
     * @return array{state: string, code?: string} state: none, pending, connected, rejected or expired
     */
    public function poll(int $idShop): array
    {
        $pairing = Settings::pairing($idShop);
        if ($pairing === null) {
            return ['state' => 'none'];
        }
        if (($pairing['outcome'] ?? 'pending') !== 'pending') {
            return ['state' => (string) $pairing['outcome'], 'code' => (string) ($pairing['code'] ?? '')];
        }
        if (!is_string($pairing['secret_key'] ?? null)) {
            return ['state' => 'none'];
        }

        try {
            $status = $this->api->send('GET', '/integration/v1/pairing-requests/' . rawurlencode((string) $pairing['request_id']), null, ApiClient::PAIRING_KEY_ID, $pairing['secret_key']);
        } catch (ApiException $exception) {
            if ($exception->getProblemCode() === 'not_found') {
                return $this->finish($idShop, $pairing, 'expired', '');
            }

            return ['state' => 'pending', 'code' => $exception->getProblemCode()];
        }

        switch ($status['status'] ?? '') {
            case 'consumed':
                $confirmation = (string) $pairing['confirmation_url'];
                Settings::saveConnection($idShop, [
                    'key_id' => (string) ($status['key_id'] ?? ''),
                    'shop_id' => (string) ($status['shop_id'] ?? ''),
                    'domain' => (string) $pairing['domain'],
                    'secret_key' => $pairing['secret_key'],
                    'saas_keys' => is_array($status['saas_signing_keys'] ?? null) ? $status['saas_signing_keys'] : [],
                    'dashboard_url' => (string) preg_replace('~^(https?://[^/]+).*$~', '$1', $confirmation),
                    'connected_at' => date('c'),
                ]);
                Settings::clearPairing($idShop);

                return ['state' => 'connected'];
            case 'rejected':
                return $this->finish($idShop, $pairing, 'rejected', (string) ($status['code'] ?? ''));
            case 'expired':
                return $this->finish($idShop, $pairing, 'expired', '');
            default:
                return ['state' => 'pending'];
        }
    }

    /**
     * The signed answer to ChatPuff's domain challenge, or null when no pairing is waiting for one.
     */
    public function answerChallenge(int $idShop, string $challenge): ?string
    {
        $pairing = Settings::pairing($idShop);
        if ($pairing === null || ($pairing['outcome'] ?? '') !== 'pending' || !is_string($pairing['secret_key'] ?? null)) {
            return null;
        }

        return Crypto::sign($pairing['secret_key'], "CHATPUFF-DOMAIN-V1\n" . $pairing['domain'] . "\n" . $challenge);
    }

    /**
     * @return array<string, mixed> shop_name, organization_name, widget_published
     *
     * @throws ApiException
     */
    public function connectionStatus(int $idShop): array
    {
        return $this->send($idShop, 'GET', '/integration/v1/connection');
    }

    /**
     * Reports this installation's versions; also the connection's heartbeat.
     *
     * @throws ApiException
     */
    public function reportInstallation(int $idShop): void
    {
        $connection = $this->usableConnection($idShop);
        $this->api->send('PUT', '/integration/v1/installation', ['metadata' => self::metadata()], (string) $connection['key_id'], (string) $connection['secret_key']);
    }

    /**
     * A signed Integration API call for a connected shop, never from a copy of it.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function send(int $idShop, string $method, string $path, ?array $body = null): array
    {
        $connection = $this->usableConnection($idShop);
        try {
            return $this->api->send($method, $path, $body, (string) $connection['key_id'], (string) $connection['secret_key']);
        } catch (ApiException $exception) {
            if (in_array($exception->getProblemCode(), ['connection_revoked', 'unknown_key'], true)) {
                // Disconnected on the ChatPuff side: forget the key here too.
                Settings::clearConnection($idShop);
            }

            throw $exception;
        }
    }

    /**
     * @throws ApiException when ChatPuff cannot be reached; the connection is then kept
     */
    public function disconnect(int $idShop): void
    {
        $connection = $this->usableConnection($idShop);
        try {
            $this->api->send('DELETE', '/integration/v1/connection', null, (string) $connection['key_id'], (string) $connection['secret_key']);
        } catch (ApiException $exception) {
            if (!in_array($exception->getProblemCode(), ['connection_revoked', 'unknown_key'], true)) {
                throw $exception;
            }
        }
        Settings::clearConnection($idShop);
    }

    public function isConnected(int $idShop): bool
    {
        return Settings::connection($idShop) !== null;
    }

    public function currentDomain(int $idShop): string
    {
        return ShopDomain::normalize((new \Shop($idShop))->getBaseURL(true));
    }

    /**
     * A copy of a connected shop (a staging site, a restored backup on another address) carries the
     * live shop's key. It must not act for the live shop, so the connection is used only on the
     * domain it was made for.
     */
    public function isCopy(int $idShop): bool
    {
        $connection = Settings::connection($idShop);

        return $connection !== null && (string) $connection['domain'] !== $this->currentDomain($idShop);
    }

    /**
     * On a copy: drop the live shop's key and take a new installation identity, so that this copy
     * can be connected as a shop of its own.
     */
    public function forgetCopy(int $idShop): void
    {
        if ($this->isCopy($idShop)) {
            Settings::clearConnection($idShop);
            Settings::clearPairing($idShop);
            Settings::resetInstallationId();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(): array
    {
        return [
            'platform_version' => _PS_VERSION_,
            'php_version' => PHP_VERSION,
            'integration_version' => ApiClient::MODULE_VERSION,
            'api_contract_version' => ApiClient::API_CONTRACT_VERSION,
            // Added as the features ship.
            'capabilities' => ['customer_identity', 'back_office_inbox', 'knowledge_sync'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function usableConnection(int $idShop): array
    {
        $connection = Settings::connection($idShop);
        if ($connection === null || $this->isCopy($idShop)) {
            throw new ApiException('not_connected', 0);
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $pairing
     *
     * @return array{state: string, code: string}
     */
    private function finish(int $idShop, array $pairing, string $outcome, string $code): array
    {
        unset($pairing['secret_key']);
        $pairing['outcome'] = $outcome;
        $pairing['code'] = $code;
        Settings::savePairing($idShop, $pairing);

        return ['state' => $outcome, 'code' => $code];
    }
}
