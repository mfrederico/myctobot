<?php
/**
 * Encryption Service
 * Provides secure encryption for sensitive data like API keys using libsodium
 */

namespace app\services;

use \Flight as Flight;

class EncryptionService {

    /**
     * Encrypt a plaintext string
     * Returns base64-encoded ciphertext with nonce prepended
     *
     * @param string $plaintext The data to encrypt
     * @return string Base64-encoded encrypted data
     * @throws \Exception If encryption fails
     */
    public static function encrypt(string $plaintext): string {
        $key = self::getKey();

        // Generate a random nonce (24 bytes for XSalsa20)
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // Encrypt the plaintext
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        // Prepend nonce to ciphertext and base64 encode
        $encrypted = base64_encode($nonce . $ciphertext);

        // Clear sensitive data from memory
        sodium_memzero($key);

        return $encrypted;
    }

    /**
     * Decrypt a ciphertext string
     *
     * @param string $encrypted Base64-encoded encrypted data (nonce + ciphertext)
     * @return string The decrypted plaintext
     * @throws \Exception If decryption fails
     */
    public static function decrypt(string $encrypted): string {
        $key = self::getKey();

        // Decode from base64
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            throw new \Exception('Invalid encrypted data: base64 decode failed');
        }

        // Check minimum length (nonce + at least 1 byte ciphertext + MAC)
        $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1;
        if (strlen($decoded) < $minLength) {
            throw new \Exception('Invalid encrypted data: too short');
        }

        // Extract nonce and ciphertext
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // Decrypt
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        // Clear sensitive data from memory
        sodium_memzero($key);

        if ($plaintext === false) {
            throw new \Exception('Decryption failed: invalid key or corrupted data');
        }

        return $plaintext;
    }

    /**
     * Test if a string is valid encrypted data
     *
     * @param string $encrypted The potentially encrypted string
     * @return bool True if it appears to be valid encrypted data
     */
    public static function isEncrypted(string $encrypted): bool {
        // Check if it's valid base64
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            return false;
        }

        // Check minimum length
        $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1;
        return strlen($decoded) >= $minLength;
    }

    /**
     * Get the encryption key from configuration
     * Key should be a 64-character hex string (32 bytes)
     *
     * @return string The binary encryption key
     * @throws \Exception If key is not configured or invalid
     */
    private static function getKey(): string {
        $hexKey = Flight::get('encryption.master_key');

        if (empty($hexKey)) {
            throw new \Exception('Encryption master key not configured. Add encryption.master_key to config.ini');
        }

        // Key should be 64 hex characters (32 bytes)
        if (strlen($hexKey) !== 64 || !ctype_xdigit($hexKey)) {
            throw new \Exception('Invalid encryption key format. Must be 64 hex characters (32 bytes).');
        }

        return hex2bin($hexKey);
    }

    /**
     * Generate a new encryption key
     * Use this to create a key for config.ini
     *
     * @return string A 64-character hex string suitable for config.ini
     */
    public static function generateKey(): string {
        return bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * Safely compare two strings in constant time
     * Use this for comparing sensitive values like API keys
     *
     * @param string $a First string
     * @param string $b Second string
     * @return bool True if strings are equal
     */
    public static function constantTimeCompare(string $a, string $b): bool {
        return hash_equals($a, $b);
    }

    /**
     * Hash a value for storage (one-way)
     * Use this for values that don't need to be decrypted (e.g., webhook secrets)
     *
     * @param string $value The value to hash
     * @return string The hashed value
     */
    public static function hash(string $value): string {
        return sodium_crypto_generichash($value, '', SODIUM_CRYPTO_GENERICHASH_BYTES);
    }

    /**
     * Hash a value and return as hex string
     *
     * @param string $value The value to hash
     * @return string The hex-encoded hash
     */
    public static function hashHex(string $value): string {
        return bin2hex(self::hash($value));
    }
}
