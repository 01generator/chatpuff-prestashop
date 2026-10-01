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
if (!defined('_PS_VERSION_')) {
    exit;
}

use ChatPuff\PrestaShop\ApiClient;
use ChatPuff\PrestaShop\ApiException;
use ChatPuff\PrestaShop\Knowledge;
use ChatPuff\PrestaShop\Pairing;
use ChatPuff\PrestaShop\Settings;

/**
 * 0.7.0: the knowledge synchronization (api-contract.md §7.6): the table that remembers what was
 * sent, and the report that tells ChatPuff about the new capability at once.
 *
 * @param Chatpuff $module
 */
function upgrade_module_0_7_0($module)
{
    if (!Knowledge::installTable()) {
        return false;
    }
    $pairing = new Pairing(new ApiClient(), Context::getContext()->link);
    foreach (Settings::connectedShopIds() as $idShop) {
        try {
            if (!$pairing->isCopy($idShop) && Settings::claimReport($idShop, time())) {
                $pairing->reportInstallation($idShop);
            }
        } catch (ApiException $exception) {
            // Reported again with the next report.
        }
    }

    return true;
}
