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
 * Signed calls to the ChatPuff Integration API (api-contract.md §5). Plain cURL, because
 * PrestaShop 1.7.8 and 9.x ship different HTTP client libraries.
 */
final class ApiClient
{
    public const MODULE_VERSION = '0.12.0';
    public const API_CONTRACT_VERSION = '1';
    public const PAIRING_KEY_ID = 'pairing';

    /** @var string */
    private $baseUrl;

    /** @var int seconds to add to the local clock, learned from ChatPuff's Date header */
    private $clockOffset = 0;

    public function __construct(?string $baseUrl = null)
    {
        // Developers point the module at a local ChatPuff with _CHATPUFF_API_URL_ in
        // config/defines_custom.inc.php; shops always use the production address.
        $this->baseUrl = rtrim($baseUrl ?? (defined('_CHATPUFF_API_URL_') ? (string) constant('_CHATPUFF_API_URL_') : 'https://api.chatpuff.com'), '/');
    }

    /**
     * ChatPuff's API address, which also serves the storefront widget's scripts.
     */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed> the decoded response body
     *
     * @throws ApiException
     */
    public function send(string $method, string $path, ?array $body, string $keyId, string $secretKey): array
    {
        $response = $this->exchange($method, $path, $body, $keyId, $secretKey);

        // A wrong server clock: correct it from ChatPuff's Date header and try once more.
        if ($response['status'] === 401 && self::problemCode($response) === 'timestamp_out_of_range' && $response['date'] !== null) {
            $this->clockOffset = $response['date'] - time();
            $response = $this->exchange($method, $path, $body, $keyId, $secretKey);
        }
        if ($response['status'] >= 200 && $response['status'] < 300) {
            return $response['body'];
        }

        throw new ApiException(self::problemCode($response), $response['status'], is_string($response['body']['reference'] ?? null) ? $response['body']['reference'] : '');
    }

    /**
     * @param array{status: int, body: array<string, mixed>, date: int|null} $response
     */
    private static function problemCode(array $response): string
    {
        return is_string($response['body']['code'] ?? null) ? $response['body']['code'] : 'http_' . $response['status'];
    }

    public static function signingString(string $method, string $pathAndQuery, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", ['CHATPUFF-ED25519-V1', strtoupper($method), $pathAndQuery, $timestamp, $nonce, hash('sha256', $body)]);
    }

    public static function clientHeader(): string
    {
        return sprintf('prestashop-module/%s (PrestaShop %s; PHP %s)', self::MODULE_VERSION, _PS_VERSION_, PHP_VERSION);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, body: array<string, mixed>, date: int|null}
     */
    private function exchange(string $method, string $path, ?array $body, string $keyId, string $secretKey): array
    {
        $content = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) (time() + $this->clockOffset);
        $nonce = bin2hex(random_bytes(16));
        $signature = Crypto::sign($secretKey, self::signingString($method, $path, $timestamp, $nonce, $content));

        $date = null;
        $curl = curl_init($this->baseUrl . $path);
        if ($curl === false) {
            throw new ApiException('network_error', 0);
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Chatpuff-Key-Id: ' . $keyId,
                'Chatpuff-Timestamp: ' . $timestamp,
                'Chatpuff-Nonce: ' . $nonce,
                'Chatpuff-Signature: ' . $signature,
                'Chatpuff-Client: ' . self::clientHeader(),
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$date): int {
                if (stripos($header, 'date:') === 0) {
                    $parsed = strtotime(trim(substr($header, 5)));
                    $date = $parsed === false ? null : $parsed;
                }

                return strlen($header);
            },
        ]);
        if ($content !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
        }

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // No curl_close(): it has done nothing since PHP 8.0 and is deprecated in PHP 8.5.
        unset($curl);
        if (!is_string($raw) || $status === 0) {
            throw new ApiException('network_error', 0);
        }

        $decoded = $raw === '' ? [] : json_decode($raw, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'date' => $date];
    }
}
