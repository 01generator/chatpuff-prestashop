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
 * 0.8.0: ChatPuff gets its own entry of the Sell section, with Inbox and Settings under it. The
 * existing tab (ChatPuff under Customer Service) becomes the entry, so the profiles that could
 * open it keep their access; the two new tabs take the same access.
 *
 * @param Chatpuff $module
 */
function upgrade_module_0_8_0($module)
{
    $idParent = (int) Tab::getIdFromClassName('AdminChatpuff');
    if ($idParent === 0) {
        $idParent = chatpuff_upgrade_0_8_0_add_tab('AdminChatpuff', 'ChatPuff', (int) Tab::getIdFromClassName('SELL'), 'chat');
        if ($idParent === 0) {
            return false;
        }
    } else {
        $parent = new Tab($idParent);
        $parent->id_parent = (int) Tab::getIdFromClassName('SELL');
        $parent->icon = 'chat';
        if (!$parent->save()) {
            return false;
        }
    }
    foreach (['AdminChatpuffInbox' => 'Inbox', 'AdminChatpuffSettings' => 'Settings'] as $className => $name) {
        if ((int) Tab::getIdFromClassName($className) > 0) {
            continue;
        }
        if (chatpuff_upgrade_0_8_0_add_tab($className, $name, $idParent, '') === 0) {
            return false;
        }
        chatpuff_upgrade_0_8_0_copy_access($className);
    }
    Chatpuff::removeDuplicatedParentTab();

    return true;
}

/**
 * A tab of the module, named the same in every language until the translations apply.
 *
 * @return int the tab's ID, 0 when it could not be saved
 */
function chatpuff_upgrade_0_8_0_add_tab(string $className, string $name, int $idParent, string $icon): int
{
    $tab = new Tab();
    $tab->class_name = $className;
    $tab->module = 'chatpuff';
    $tab->id_parent = $idParent;
    $tab->active = true;
    $tab->icon = $icon;
    $tab->wording = $name;
    $tab->wording_domain = 'Modules.Chatpuff.Admin';
    foreach (Language::getLanguages(false) as $language) {
        $tab->name[(int) $language['id_lang']] = $name;
    }

    return $tab->add() ? (int) $tab->id : 0;
}

/**
 * The profiles that may open the ChatPuff tab may open the new tabs too (Tab::add() grants the
 * super admin only).
 */
function chatpuff_upgrade_0_8_0_copy_access(string $className): void
{
    $from = 'ROLE_MOD_TAB_ADMINCHATPUFF_';
    $to = 'ROLE_MOD_TAB_' . strtoupper($className) . '_';
    Db::getInstance()->execute(
        'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'access` (id_profile, id_authorization_role)'
        . ' SELECT a.id_profile, r2.id_authorization_role'
        . ' FROM `' . _DB_PREFIX_ . 'access` a'
        . ' INNER JOIN `' . _DB_PREFIX_ . 'authorization_role` r1 ON r1.id_authorization_role = a.id_authorization_role AND r1.slug LIKE \'' . pSQL($from) . '%\''
        . ' INNER JOIN `' . _DB_PREFIX_ . 'authorization_role` r2 ON r2.slug = REPLACE(r1.slug, \'' . pSQL($from) . '\', \'' . pSQL($to) . '\')'
    );
}
