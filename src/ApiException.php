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
 * A failed call to ChatPuff: the problem code from the response (api-contract.md §11), or
 * "network_error" when ChatPuff could not be reached.
 */
final class ApiException extends \RuntimeException
{
    /** @var string */
    private $problemCode;

    /** @var string */
    private $reference;

    public function __construct(string $problemCode, int $status, string $reference = '')
    {
        parent::__construct(sprintf('ChatPuff answered %d %s', $status, $problemCode), $status);
        $this->problemCode = $problemCode;
        $this->reference = $reference;
    }

    public function getProblemCode(): string
    {
        return $this->problemCode;
    }

    /**
     * The error reference to give ChatPuff support.
     */
    public function getReference(): string
    {
        return $this->reference;
    }
}
