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
use ChatPuff\PrestaShop\CustomerToken;
use ChatPuff\PrestaShop\Pairing;
use ChatPuff\PrestaShop\Settings;
use ChatPuff\PrestaShop\ShopDomain;

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
        if (!parent::install() || !$this->registerHook('displayBeforeBodyClosingTag')) {
            return false;
        }
        Settings::installationId();

        return true;
    }

    /**
     * Adds the chat widget's script to every storefront page of a connected shop (api-contract.md
     * §7.1). ChatPuff shows the chat only once the owner publishes it, and the script loads async,
     * so it never slows the page down or breaks it.
     */
    public function hookDisplayBeforeBodyClosingTag(): string
    {
        $shop = $this->context->shop;
        $widget = Settings::widget((int) $shop->id);
        if ($widget === null) {
            return '';
        }
        // A copy of a connected shop, such as a staging site, must not show the live shop's chat.
        if (ShopDomain::normalize($shop->getBaseURL(true)) !== $widget['domain']) {
            return '';
        }

        $this->reportAfterResponse((int) $shop->id);

        return sprintf(
            '<script async src="%s" data-shop="%s" data-locale="%s"%s%s></script>',
            htmlspecialchars((new ApiClient())->baseUrl() . '/widget/v1/loader.js', ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($widget['shop_id'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $this->context->language->iso_code, ENT_QUOTES, 'UTF-8'),
            $this->privacyAttribute((int) $shop->id),
            $this->customerTokenAttribute((int) $shop->id)
        );
    }

    /**
     * The shop's privacy page, which the chat links from its notice about the name and email it asks
     * for. Chosen on the module's page; nothing when none is chosen or the page is disabled.
     */
    private function privacyAttribute(int $idShop): string
    {
        $idCms = Settings::privacyPage($idShop);
        if ($idCms <= 0) {
            return '';
        }
        $idLang = (int) $this->context->language->id;
        $cms = new CMS($idCms, $idLang, $idShop);
        if (!Validate::isLoadedObject($cms) || !$cms->active) {
            return '';
        }

        return ' data-privacy-url="' . htmlspecialchars($this->context->link->getCMSLink($cms, null, true, $idLang, $idShop), ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * The hourly installation report, which is also the connection's heartbeat (api-contract.md §7).
     * It is sent after the page has reached the visitor, so ChatPuff being slow or unreachable never
     * delays the storefront. Servers that cannot finish a response early send it from the module's
     * back-office page instead.
     */
    private function reportAfterResponse(int $idShop): void
    {
        if (function_exists('fastcgi_finish_request')) {
            $finish = static function (): void {
                fastcgi_finish_request();
            };
        } elseif (function_exists('litespeed_finish_request')) {
            $finish = static function (): void {
                litespeed_finish_request();
            };
        } else {
            return;
        }
        if (!Settings::claimReport($idShop, time())) {
            return;
        }
        $link = $this->context->link;
        register_shutdown_function(static function () use ($finish, $idShop, $link): void {
            $finish();
            try {
                (new Pairing(new ApiClient(), $link))->reportInstallation($idShop);
            } catch (Exception $exception) {
                // The next report is due in an hour; the chat works either way.
            }
        });
    }

    /**
     * A logged-in customer skips the chat's name and email form: the page carries a token, signed
     * with this shop's connection key, that says who they are (api-contract.md §7.2). Guest
     * checkout accounts are not logged in and get no token.
     */
    private function customerTokenAttribute(int $idShop): string
    {
        $customer = $this->context->customer;
        if (!Validate::isLoadedObject($customer) || !$customer->isLogged()) {
            return '';
        }
        $connection = Settings::connection($idShop);
        if ($connection === null) {
            return '';
        }

        $token = CustomerToken::create(
            $connection,
            Settings::installationId(),
            (string) $idShop,
            (string) $customer->id,
            trim($customer->firstname . ' ' . $customer->lastname),
            (string) $customer->email,
            time()
        );

        return ' data-customer-token="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"';
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
