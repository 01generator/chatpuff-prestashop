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
 * The ChatPuff inbox in this back office (api-contract.md §7.3). An employee links their back-office
 * account to their ChatPuff account once; after that, the module asks ChatPuff for 15-minute staff
 * tokens that the inbox script uses. ChatPuff decides what the person may do; the module only
 * vouches for which employee is asking.
 */
final class BackOfficeInbox
{
    /** @var Pairing */
    private $pairing;

    public function __construct(Pairing $pairing)
    {
        $this->pairing = $pairing;
    }

    /**
     * @return array<string, string> state "ready" with access_token, expires_at and shop_id, or
     *                               state "not_linked" or "no_access"
     *
     * @throws ApiException
     */
    public function token(int $idShop, int $idEmployee): array
    {
        try {
            $response = $this->pairing->send($idShop, 'POST', '/integration/v1/staff-sessions', ['external_employee_id' => (string) $idEmployee]);
        } catch (ApiException $exception) {
            if ($exception->getProblemCode() === 'employee_not_linked') {
                return ['state' => 'not_linked'];
            }
            if ($exception->getCode() === 403) {
                return ['state' => 'no_access'];
            }

            throw $exception;
        }

        return [
            'state' => 'ready',
            'access_token' => (string) ($response['access_token'] ?? ''),
            'expires_at' => (string) ($response['expires_at'] ?? ''),
            'shop_id' => (string) ($response['shop_id'] ?? ''),
        ];
    }

    /**
     * A single-use link, valid for 10 minutes, where the employee signs in to ChatPuff and confirms.
     *
     * @throws ApiException
     */
    public function linkUrl(int $idShop, \Employee $employee): string
    {
        $response = $this->pairing->send($idShop, 'POST', '/integration/v1/employee-link-requests', [
            'external_employee_id' => (string) $employee->id,
            'display_name' => self::displayName($employee),
        ]);

        return (string) ($response['link_url'] ?? '');
    }

    /**
     * Shown on ChatPuff's confirmation page so the person recognises their back-office account; the
     * first name and an initial are enough.
     */
    public static function displayName(\Employee $employee): string
    {
        $first = trim((string) $employee->firstname);
        $last = trim((string) $employee->lastname);
        $name = trim($first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : ''));

        return $name !== '' ? mb_substr($name, 0, 100) : 'Employee ' . (int) $employee->id;
    }
}
