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
use ChatPuff\PrestaShop\ApiException;
use ChatPuff\PrestaShop\Knowledge;
use ChatPuff\PrestaShop\Settings;

/**
 * ChatPuff > Settings: connects the current shop to ChatPuff (api-contract.md §6), shows its state,
 * the privacy page the chat links to and the knowledge synchronization with its progress.
 *
 * @property Chatpuff $module
 */
class AdminChatpuffSettingsController extends ChatpuffAdminController
{
    public function initContent(): void
    {
        $this->content = $this->renderConnection();
        parent::initContent();
    }

    public function postProcess()
    {
        $idShop = $this->currentShopId();
        if ($idShop === null || !(Tools::isSubmit('chatpuffConnect') || Tools::isSubmit('chatpuffDisconnect') || Tools::isSubmit('chatpuffForgetCopy') || Tools::isSubmit('chatpuffPrivacy') || Tools::isSubmit('chatpuffSyncKnowledge'))) {
            return parent::postProcess();
        }
        if (!$this->checkToken()) {
            $this->errors[] = $this->trans('Your session expired. Please try again.', [], 'Modules.Chatpuff.Admin');

            return false;
        }
        if (Tools::isSubmit('chatpuffPrivacy')) {
            return $this->savePrivacyPage($idShop);
        }
        if (Tools::isSubmit('chatpuffSyncKnowledge')) {
            return $this->syncKnowledge($idShop);
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

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminChatpuffSettings'));

        return true;
    }

    /**
     * A run of the knowledge synchronization now, with a larger budget than a storefront visit gives
     * it (api-contract.md §7.6); the page then shows where the pass stands.
     */
    private function syncKnowledge(int $idShop): bool
    {
        @set_time_limit(Knowledge::ADMIN_BUDGET + 30);
        $started = microtime(true);
        $before = Knowledge::cursor($idShop);
        $cursor = (new Knowledge(new ApiClient(), $this->context->link))->run($idShop, Knowledge::ADMIN_BUDGET);
        if ($cursor['error'] !== null) {
            $this->errors[] = $this->trans('The synchronization stopped with the error %code%. It is retried automatically.', ['%code%' => $cursor['error']], 'Modules.Chatpuff.Admin');

            return false;
        }
        $this->confirmations[] = $this->trans('Synchronized for %seconds% seconds: %checked% items checked, %sent% sent to ChatPuff.', [
            '%seconds%' => (string) (int) round(microtime(true) - $started),
            '%checked%' => (string) max(0, $cursor['checked'] - ($cursor['started_at'] === $before['started_at'] ? $before['checked'] : 0)),
            '%sent%' => (string) max(0, $cursor['sent'] - ($cursor['started_at'] === $before['started_at'] ? $before['sent'] : 0)),
        ], 'Modules.Chatpuff.Admin');

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
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminChatpuffSettings') . '&conf=4');

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
     * One step of the synchronization for views/js/admin.js, which calls it until the pass is
     * complete and draws the progress; each step runs for a few seconds. The background runs keep
     * away meanwhile.
     */
    public function ajaxProcessSyncKnowledge(): void
    {
        $this->renderJson(function (int $idShop): array {
            if (!$this->access('edit')) {
                return ['state' => 'error', 'error' => $this->trans('You do not have permission to edit this.', [], 'Admin.Notifications.Error')];
            }
            @set_time_limit(Knowledge::STEP_BUDGET + 20);
            Knowledge::touch($idShop, time());
            $cursor = (new Knowledge(new ApiClient(), $this->context->link))->run($idShop, Knowledge::STEP_BUDGET);
            $progress = Knowledge::progress($idShop);

            return [
                'state' => $cursor['error'] !== null ? 'failed' : ($cursor['kind'] === null ? 'complete' : 'running'),
                'error' => (string) $cursor['error'],
                'done' => (string) $progress['done'],
                'total' => (string) $progress['total'],
                'sent' => (string) $cursor['sent'],
                'checked' => (string) $cursor['checked'],
            ];
        });
    }

    private function renderConnection(): string
    {
        $idShop = $this->currentShopId();
        $pairing = $this->pairing();
        $view = [
            'state' => 'select_shop',
            'admin_url' => $this->context->link->getAdminLink('AdminChatpuffSettings'),
            'poll_url' => $this->context->link->getAdminLink('AdminChatpuffSettings') . '&ajax=1&action=pairingStatus',
            'start_url' => $this->context->link->getAdminLink('AdminChatpuffSettings') . '&ajax=1&action=startPairing',
            'logo_url' => $this->logoUrl(),
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
                $view['inbox_url'] = $this->context->link->getAdminLink('AdminChatpuffInbox');
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
                    // The same for the knowledge, a few seconds at a time.
                    if (!function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request') && Knowledge::claim($idShop, time())) {
                        (new Knowledge(new ApiClient(), $this->context->link))->run($idShop, Knowledge::PAGE_BUDGET);
                    }
                    $cursor = Knowledge::cursor($idShop);
                    $view['knowledge'] = [
                        'sync_url' => $this->context->link->getAdminLink('AdminChatpuffSettings') . '&ajax=1&action=syncKnowledge',
                        'in_progress' => $cursor['kind'] !== null,
                        // Date only: PrestaShop 1.7 and 9 disagree on the other parameters of displayDate().
                        'completed_at' => $cursor['completed_at'] === null ? null : Tools::displayDate(date('Y-m-d H:i:s', $cursor['completed_at'])),
                        'error' => $cursor['error'],
                    ];
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
}
