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
 * 0.9.0: the badge with what waits in ChatPuff, on every back-office page.
 *
 * @param Chatpuff $module
 */
function upgrade_module_0_9_0($module)
{
    return (bool) $module->registerHook(Chatpuff::BACK_OFFICE_HOOKS);
}
