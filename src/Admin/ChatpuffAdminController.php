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

namespace ChatPuff\PrestaShop\Admin;

use ChatPuff\PrestaShop\ApiClient;
use ChatPuff\PrestaShop\ApiException;
use ChatPuff\PrestaShop\BackOfficeInbox;
use ChatPuff\PrestaShop\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * What the module's back-office pages share: the current shop, the API services, the module's
 * assets, the JSON answers of their ajax actions and the wording of a failed call.
 *
 * @property \Chatpuff $module
 */
abstract class ChatpuffAdminController extends \ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function setMedia($isNewTheme = false): void
    {
        parent::setMedia($isNewTheme);
        // The version makes browsers fetch the files again after an upgrade.
        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css?v=' . ApiClient::MODULE_VERSION);
        $this->addJS($this->module->getPathUri() . 'views/js/admin.js?v=' . ApiClient::MODULE_VERSION);
    }

    /** The shop of the context, or null when the context is a group or all shops. */
    protected function currentShopId(): ?int
    {
        if (\Shop::isFeatureActive() && \Shop::getContext() !== \Shop::CONTEXT_SHOP) {
            return null;
        }

        return (int) $this->context->shop->id;
    }

    protected function pairing(): Pairing
    {
        return new Pairing(new ApiClient(), $this->context->link);
    }

    protected function backOfficeInbox(): BackOfficeInbox
    {
        return new BackOfficeInbox($this->pairing());
    }

    protected function logoUrl(): string
    {
        return $this->module->getPathUri() . 'logo.png';
    }

    /**
     * Answers an ajax action with JSON, for the current shop, once the token and the right to view
     * the tab are checked.
     *
     * @param \Closure(int): array<string, string> $action
     */
    protected function renderJson(\Closure $action): void
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

    protected function apiError(ApiException $exception): string
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
