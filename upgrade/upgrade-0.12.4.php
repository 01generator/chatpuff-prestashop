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
 * 0.12.4: a product changed in the shop (saved, created, deleted, its stock, its combinations or a
 * price of its own) reaches the assistant's knowledge once that request's response is out, instead
 * of waiting for the hourly pass: the module listens to those changes from now on.
 *
 * @param Chatpuff $module
 */
function upgrade_module_0_12_4($module)
{
    return $module->registerHook(Chatpuff::KNOWLEDGE_HOOKS);
}
