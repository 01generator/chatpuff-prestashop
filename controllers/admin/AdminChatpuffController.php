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
use ChatPuff\PrestaShop\BackOfficeInbox;
use ChatPuff\PrestaShop\Pairing;
use ChatPuff\PrestaShop\Settings;

/**
 * Customer Service > ChatPuff: connects this shop to ChatPuff, and once it is connected, shows the
 * ChatPuff inbox to employees who linked their ChatPuff account.
 *
 * @property Chatpuff $module
 */
class AdminChatpuffController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent(): void
    {
        $this->content = $this->renderConnection();
        parent::initContent();
    }

    public function setMedia($isNewTheme = false): void
    {
        parent::setMedia($isNewTheme);
        // The version makes browsers fetch the files again after an upgrade.
        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css?v=' . ApiClient::MODULE_VERSION);
        $this->addJS($this->module->getPathUri() . 'views/js/admin.js?v=' . ApiClient::MODULE_VERSION);
    }

    public function postProcess()
    {
        $idShop = $this->currentShopId();
        if ($idShop === null || !(Tools::isSubmit('chatpuffConnect') || Tools::isSubmit('chatpuffDisconnect') || Tools::isSubmit('chatpuffForgetCopy') || Tools::isSubmit('chatpuffPrivacy'))) {
            return parent::postProcess();
        }
        if (!$this->checkToken()) {
            $this->errors[] = $this->trans('Your session expired. Please try again.', [], 'Modules.Chatpuff.Admin');

            return false;
        }
        if (Tools::isSubmit('chatpuffPrivacy')) {
            return $this->savePrivacyPage($idShop);
        }

        $pairing = $this->pairing();
        try {
            if (Tools::isSubmit('chatpuffConnect')) {
                $pairing->start($idShop);
            } elseif (Tools::isSubmit('chatpuffDisconnect')) {
                $pairing->disconnect($idShop);
            } else {
                $pairing->forgetCopy($idShop);
            }
        } catch (ApiException $exception) {
            $this->errors[] = $this->apiError($exception);

            return false;
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminChatpuff'));

        return true;
    }

    /**
     * The privacy page the chat links to (api-contract.md §7.1): one of this shop's CMS pages, or none.
     */
    private function savePrivacyPage(int $idShop): bool
    {
        $idCms = (int) Tools::getValue('chatpuff_privacy_cms');
        if ($idCms !== 0 && !in_array($idCms, array_column($this->cmsPages($idShop), 'id_cms'), true)) {
            $this->errors[] = $this->trans('Choose one of this shop\'s pages.', [], 'Modules.Chatpuff.Admin');

            return false;
        }
        Settings::savePrivacyPage($idShop, $idCms);
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminChatpuff') . '&conf=4');

        return true;
    }

    /**
     * @return list<array{id_cms: int, title: string}> the shop's active CMS pages, in the employee's language
     */
    private function cmsPages(int $idShop): array
    {
        $pages = [];
        foreach (CMS::getCMSPages((int) $this->context->language->id, null, true, $idShop) as $page) {
            $pages[] = ['id_cms' => (int) $page['id_cms'], 'title' => (string) $page['meta_title']];
        }

        return $pages;
    }

    /**
     * The Connect button with JavaScript: views/js/admin.js opens the returned link in a new tab.
     */
    public function ajaxProcessStartPairing(): void
    {
        $idShop = $this->currentShopId();
        $result = ['error' => $this->trans('Your session expired. Please try again.', [], 'Modules.Chatpuff.Admin')];
        if ($idShop !== null && $this->checkToken()) {
            try {
                $result = ['confirmation_url' => $this->pairing()->start($idShop)];
            } catch (ApiException $exception) {
                $result = ['error' => $this->apiError($exception)];
            }
        }

        $this->ajaxRender((string) json_encode($result));
        exit;
    }

    /**
     * Polled every three seconds by views/js/admin.js while the owner confirms in ChatPuff.
     */
    public function ajaxProcessPairingStatus(): void
    {
        $idShop = $this->currentShopId();
        $result = $idShop === null || !$this->checkToken() ? ['state' => 'none'] : $this->pairing()->poll($idShop);

        $this->ajaxRender((string) json_encode($result));
        exit;
    }

    /**
     * A staff token for the inbox (api-contract.md §7.3), asked for by views/js/admin.js when the page
     * opens and before the token expires. PrestaShop has already checked that the employee may open
     * this tab.
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

    /**
     * @param Closure(int): array<string, string> $action
     */
    private function renderJson(Closure $action): void
    {
        $idShop = $this->currentShopId();
        $result = ['state' => 'error', 'error' => $this->trans('Your session expired. Please try again.', [], 'Modules.Chatpuff.Admin')];
        if ($idShop !== null && $this->checkToken() && $this->access('view')) {
            try {
                $result = $action($idShop);
            } catch (ApiException $exception) {
                $result = ['state' => 'error', 'error' => $this->apiError($exception)];
            }
        }

        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxRender((string) json_encode($result));
        exit;
    }

    private function renderConnection(): string
    {
        $idShop = $this->currentShopId();
        $pairing = $this->pairing();
        $view = [
            'state' => 'select_shop',
            'admin_url' => $this->context->link->getAdminLink('AdminChatpuff'),
            'poll_url' => $this->context->link->getAdminLink('AdminChatpuff') . '&ajax=1&action=pairingStatus',
            'start_url' => $this->context->link->getAdminLink('AdminChatpuff') . '&ajax=1&action=startPairing',
            'logo_url' => $this->module->getPathUri() . 'logo.png',
            'https_enabled' => (bool) Configuration::get('PS_SSL_ENABLED'),
            'error' => null,
        ];

        if ($idShop !== null) {
            $view['domain'] = $pairing->currentDomain($idShop);
            $connection = Settings::connection($idShop);
            $request = Settings::pairing($idShop);

            if ($connection !== null && $pairing->isCopy($idShop)) {
                $view['state'] = 'copy';
                $view['connected_domain'] = $connection['domain'];
            } elseif ($connection !== null) {
                $view['state'] = 'connected';
                $view['inbox'] = [
                    'token_url' => $this->context->link->getAdminLink('AdminChatpuff') . '&ajax=1&action=staffToken',
                    'link_url' => $this->context->link->getAdminLink('AdminChatpuff') . '&ajax=1&action=employeeLink',
                    'api' => (new ApiClient())->baseUrl(),
                    'locale' => (string) $this->context->language->iso_code,
                ];
                // The shop's setup page in ChatPuff, where the owner publishes the chat widget.
                $view['dashboard_url'] = rtrim((string) ($connection['dashboard_url'] ?? 'https://app.chatpuff.com'), '/')
                    . (is_string($connection['shop_id'] ?? null) && $connection['shop_id'] !== '' ? '/shops/' . rawurlencode($connection['shop_id']) : '');
                $view['privacy'] = ['pages' => $this->cmsPages($idShop), 'selected' => Settings::privacyPage($idShop)];
                try {
                    $view['status'] = $pairing->connectionStatus($idShop);
                    // The hourly report, for servers where the storefront cannot send it after the
                    // response (chatpuff.php); it waits for nobody here, the page already calls ChatPuff.
                    if (Settings::claimReport($idShop, time())) {
                        $pairing->reportInstallation($idShop);
                    }
                } catch (ApiException $exception) {
                    $view['state'] = $pairing->isConnected($idShop) ? 'connected' : 'not_connected';
                    $view['error'] = $this->apiError($exception);
                }
            } elseif ($request !== null) {
                $result = $pairing->poll($idShop);
                $view['state'] = $result['state'] === 'connected' ? 'just_connected' : $result['state'];
                $view['rejection'] = $result['code'] ?? '';
                // The address ChatPuff calls to check the domain, for the help shown when that check fails.
                $view['callback_url'] = $this->context->link->getModuleLink('chatpuff', 'callback', [], true, null, $idShop);
                $view['confirmation_url'] = $request['confirmation_url'] ?? '';
            } else {
                $view['state'] = 'not_connected';
            }
        }

        $this->context->smarty->assign(['chatpuff' => $view]);

        return $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/connection.tpl');
    }

    private function currentShopId(): ?int
    {
        if (Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return null;
        }

        return (int) $this->context->shop->id;
    }

    private function pairing(): Pairing
    {
        return new Pairing(new ApiClient(), $this->context->link);
    }

    private function backOfficeInbox(): BackOfficeInbox
    {
        return new BackOfficeInbox($this->pairing());
    }

    private function apiError(ApiException $exception): string
    {
        if ($exception->getProblemCode() === 'network_error') {
            return $this->trans('ChatPuff could not be reached. Check that your server can make outgoing HTTPS connections, then try again.', [], 'Modules.Chatpuff.Admin');
        }
        if ($exception->getProblemCode() === 'rate_limited') {
            return $this->trans('Too many attempts. Please wait a few minutes and try again.', [], 'Modules.Chatpuff.Admin');
        }

        return $this->trans('ChatPuff could not complete the request (%code%). Error reference: %reference%', ['%code%' => $exception->getProblemCode(), '%reference%' => $exception->getReference() !== '' ? $exception->getReference() : '-'], 'Modules.Chatpuff.Admin');
    }
}
