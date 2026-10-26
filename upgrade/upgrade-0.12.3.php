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

/**
 * 0.12.3: the assistant's knowledge carries the price a guest in the shop's default country sees,
 * in the shop's default currency, whoever's visit runs the synchronization, so a product is sent
 * again only when its price really changes. Nothing to change in the shop: the next pass sends
 * each product once with the new price line.
 *
 * @param Chatpuff $module
 */
function upgrade_module_0_12_3($module)
{
    return true;
}
