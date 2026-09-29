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
use ChatPuff\PrestaShop\Pairing;

/**
 * ChatPuff calls this endpoint on the shop's own domain (api-contract.md §6.3):
 * ?action=verify&challenge=… proves that this domain reaches the installation holding the key.
 */
class ChatpuffCallbackModuleFrontController extends ModuleFrontController
{
    public function init(): void
    {
        // Anyone may call it (it reveals nothing without the challenge). JSON only: Controller::run()
        // then calls displayAjax{Action}() instead of rendering a page.
        $this->auth = false;
        $this->ajax = true;
        parent::init();
    }

    /**
     * ChatPuff's servers must reach this endpoint while the shop is in maintenance mode, as a new
     * or test shop often is: parent::init() would answer them with the 503 maintenance page.
     */
    protected function displayMaintenancePage(): void
    {
    }

    /**
     * Nor may the shop's country restrictions (geolocation) turn ChatPuff's servers away.
     */
    protected function displayRestrictedCountryPage(): void
    {
    }

    public function displayAjaxVerify(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');

        $challenge = (string) Tools::getValue('challenge');
        if (preg_match('/^[0-9a-f]{64}$/', $challenge) !== 1) {
            http_response_code(400);
            $this->ajaxRender((string) json_encode(['code' => 'invalid_challenge']));

            return;
        }

        $signature = (new Pairing(new ApiClient(), $this->context->link))->answerChallenge((int) $this->context->shop->id, $challenge);
        if ($signature === null) {
            http_response_code(404);
            $this->ajaxRender((string) json_encode(['code' => 'no_pairing_in_progress']));

            return;
        }

        $this->ajaxRender((string) json_encode(['challenge' => $challenge, 'signature' => $signature]));
    }

    public function displayAjax(): void
    {
        header('Content-Type: application/json');
        http_response_code(404);
        $this->ajaxRender((string) json_encode(['code' => 'not_found']));
    }
}
