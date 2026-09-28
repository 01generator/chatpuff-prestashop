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
 * Ed25519 signatures and encryption at rest, with ext-sodium or the sodium_compat polyfill.
 */
final class Crypto
{
    /**
     * @return array{secret: string, public: string} base64 keys
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    public static function sign(string $secretKeyBase64, string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, (string) base64_decode($secretKeyBase64, true)));
    }

    public static function verify(string $publicKeyBase64, string $message, string $signatureBase64): bool
    {
        $key = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($signatureBase64, true);
        if ($key === false || $signature === false || strlen($key) !== 32 || strlen($signature) !== 64) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $message, $key);
    }

    /**
     * Private keys are stored encrypted with a key derived from the shop's cookie key, which lives in
     * the PrestaShop configuration files, not in the database. A database dump alone therefore does
     * not reveal them.
     */
    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::storageKey()));
    }

    public static function decrypt(string $ciphertext): ?string
    {
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plaintext = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::storageKey());

        return $plaintext === false ? null : $plaintext;
    }

    private static function storageKey(): string
    {
        $secret = defined('_COOKIE_KEY_') ? (string) constant('_COOKIE_KEY_') : '';
        if ($secret === '' && defined('_NEW_COOKIE_KEY_')) {
            $secret = (string) constant('_NEW_COOKIE_KEY_');
        }
        if ($secret === '') {
            throw new \RuntimeException('The shop has no cookie key to protect the ChatPuff keys.');
        }

        return sodium_crypto_generichash('chatpuff-key-storage|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
