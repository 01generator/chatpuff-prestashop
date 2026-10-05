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

require_once _PS_MODULE_DIR_ . 'chatpuff/vendor/autoload.php';

use ChatPuff\PrestaShop\Admin\ChatpuffAdminController;
use ChatPuff\PrestaShop\ApiClient;
use ChatPuff\PrestaShop\OrderCallback;
use ChatPuff\PrestaShop\Settings;

/**
 * ChatPuff > Inbox: the ChatPuff inbox for employees who linked their ChatPuff account
 * (api-contract.md §7.3). The module gives the page 15-minute staff tokens; ChatPuff's own script
 * draws the inbox.
 *
 * @property Chatpuff $module
 */
class AdminChatpuffInboxController extends ChatpuffAdminController
{
    public function initContent(): void
    {
        $this->content = $this->renderInbox();
        parent::initContent();
    }

    /**
     * A staff token for the inbox, asked for by views/js/admin.js when the page opens and before
     * the token expires. PrestaShop has already checked that the employee may open this tab.
     */
    public function ajaxProcessStaffToken(): void
    {
        $this->renderJson(function (int $idShop): array {
            return $this->backOfficeInbox()->token($idShop, (int) $this->context->employee->id);
        });
    }

    /**
     * The link that opens in a ChatPuff window, where the employee signs in and confirms.
     */
    public function ajaxProcessEmployeeLink(): void
    {
        $this->renderJson(function (int $idShop): array {
            return ['state' => 'linking', 'link_url' => $this->backOfficeInbox()->linkUrl($idShop, $this->context->employee)];
        });
    }

    private function renderInbox(): string
    {
        $idShop = $this->currentShopId();
        $view = [
            'state' => 'select_shop',
            'logo_url' => $this->logoUrl(),
            'settings_url' => $this->context->link->getAdminLink('AdminChatpuffSettings'),
        ];
        if ($idShop !== null) {
            $connection = Settings::connection($idShop);
            if ($connection !== null && !$this->pairing()->isCopy($idShop)) {
                $view['state'] = 'connected';
                $view['inbox'] = [
                    'token_url' => $this->context->link->getAdminLink('AdminChatpuffInbox') . '&ajax=1&action=staffToken',
                    'link_url' => $this->context->link->getAdminLink('AdminChatpuffInbox') . '&ajax=1&action=employeeLink',
                    'api' => (new ApiClient())->baseUrl(),
                    'locale' => (string) $this->context->language->iso_code,
                    // A verified order links to its own page here, where PrestaShop checks the employee's permissions.
                    'order_url' => OrderCallback::adminOrderUrl($this->context->link),
                ];
            } else {
                $view['state'] = 'not_connected';
            }
        }

        $this->context->smarty->assign(['chatpuff' => $view]);

        return $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/inbox.tpl');
    }
}
