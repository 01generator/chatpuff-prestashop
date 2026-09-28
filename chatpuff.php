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

require_once __DIR__ . '/vendor/autoload.php';

use ChatPuff\PrestaShop\ApiClient;
use ChatPuff\PrestaShop\ApiException;
use ChatPuff\PrestaShop\Pairing;
use ChatPuff\PrestaShop\Settings;

class Chatpuff extends Module
{
    public function __construct()
    {
        $this->name = 'chatpuff';
        $this->tab = 'front_office_features';
        $this->version = ApiClient::MODULE_VERSION;
        $this->author = 'ChatPuff';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
        $this->tabs = [
            [
                'name' => 'ChatPuff',
                'class_name' => 'AdminChatpuff',
                'visible' => true,
                'parent_class_name' => 'AdminParentCustomerThreads',
                'wording' => 'ChatPuff',
                'wording_domain' => 'Modules.Chatpuff.Admin',
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('ChatPuff live chat', [], 'Modules.Chatpuff.Admin');
        $this->description = $this->trans('Live chat with your customers, answered from your back office, the ChatPuff dashboard or your phone.', [], 'Modules.Chatpuff.Admin');
        $this->confirmUninstall = $this->trans('Uninstalling disconnects your shops from ChatPuff. Their chat history stays in ChatPuff. Continue?', [], 'Modules.Chatpuff.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }
        Settings::installationId();

        return true;
    }

    public function uninstall(): bool
    {
        // Tell ChatPuff, so the owners know. Best effort: uninstalling must work offline too.
        $pairing = new Pairing(new ApiClient(), $this->context->link);
        foreach (Settings::connectedShopIds() as $idShop) {
            try {
                $pairing->disconnect($idShop);
            } catch (ApiException $exception) {
                // The owner sees the shop as connected until they disconnect it in ChatPuff.
            }
        }
        Settings::deleteAll();

        return parent::uninstall();
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminChatpuff'));
    }
}
