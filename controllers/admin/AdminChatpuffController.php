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
use ChatPuff\PrestaShop\Pairing;
use ChatPuff\PrestaShop\Settings;

/**
 * Customer Service > ChatPuff: connects this shop to ChatPuff and shows the connection.
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
        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css');
        $this->addJS($this->module->getPathUri() . 'views/js/admin.js');
    }

    public function postProcess()
    {
        $idShop = $this->currentShopId();
        if ($idShop === null || !(Tools::isSubmit('chatpuffConnect') || Tools::isSubmit('chatpuffDisconnect') || Tools::isSubmit('chatpuffForgetCopy'))) {
            return parent::postProcess();
        }
        if (!$this->checkToken()) {
            $this->errors[] = $this->trans('Your session expired. Please try again.', [], 'Modules.Chatpuff.Admin');

            return false;
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
                $view['dashboard_url'] = $connection['dashboard_url'] ?? 'https://app.chatpuff.com';
                try {
                    $view['status'] = $pairing->connectionStatus($idShop);
                } catch (ApiException $exception) {
                    $view['state'] = $pairing->isConnected($idShop) ? 'connected' : 'not_connected';
                    $view['error'] = $this->apiError($exception);
                }
            } elseif ($request !== null) {
                $result = $pairing->poll($idShop);
                $view['state'] = $result['state'] === 'connected' ? 'just_connected' : $result['state'];
                $view['rejection'] = $result['code'] ?? '';
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
