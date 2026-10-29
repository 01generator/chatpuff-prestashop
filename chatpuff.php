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
use ChatPuff\PrestaShop\Knowledge;
use ChatPuff\PrestaShop\Pairing;
use ChatPuff\PrestaShop\Settings;
use ChatPuff\PrestaShop\ShopDomain;
use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

class Chatpuff extends Module implements WidgetInterface
{
    /**
     * Where the chat's script goes, first come first served: the theme's closing-tag hook, the
     * displayChatPuff hook for themes and page builders that leave that one out, and, failing both,
     * the finished page itself.
     */
    public const STOREFRONT_HOOKS = ['displayBeforeBodyClosingTag', 'displayChatPuff', 'actionOutputHTMLBefore'];

    /**
     * The back-office pages: the badge with what waits in ChatPuff, next to the menu entries.
     */
    public const BACK_OFFICE_HOOKS = ['displayBackOfficeHeader'];

    /**
     * What changes a product for the assistant's knowledge (0.12.4): the product itself, its stock,
     * its combinations and the prices of its own. Each is sent once the response is out, instead of
     * waiting for the next pass.
     */
    public const KNOWLEDGE_HOOKS = [
        'actionObjectProductAddAfter', 'actionObjectProductUpdateAfter', 'actionObjectProductDeleteAfter',
        'actionUpdateQuantity',
        'actionObjectCombinationAddAfter', 'actionObjectCombinationUpdateAfter', 'actionObjectCombinationDeleteAfter',
        'actionObjectSpecificPriceAddAfter', 'actionObjectSpecificPriceUpdateAfter', 'actionObjectSpecificPriceDeleteAfter',
    ];

    /**
     * The module version whose menu was last checked for the copy of the parent entry that
     * PrestaShop keeps as its first child (0.12.2): checked again once after every upgrade.
     */
    public const MENU_CLEANED = 'CHATPUFF_MENU_CLEANED';

    /**
     * @var bool whether this page already has the chat's script
     */
    private static $widgetAdded = false;

    /**
     * @var bool whether this request sends the changed products once its response is out
     */
    private static $changesQueued = false;

    public function __construct()
    {
        $this->name = 'chatpuff';
        $this->tab = 'front_office_features';
        $this->version = ApiClient::MODULE_VERSION;
        $this->author = 'ChatPuff';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
        // ChatPuff's own entry of the Sell section, with Inbox and Settings under it (0.8.0).
        $this->tabs = [
            [
                'name' => 'ChatPuff',
                'class_name' => 'AdminChatpuff',
                'visible' => true,
                'parent_class_name' => 'SELL',
                'icon' => 'chat',
                'wording' => 'ChatPuff',
                'wording_domain' => 'Modules.Chatpuff.Admin',
            ],
            [
                'name' => 'Inbox',
                'class_name' => 'AdminChatpuffInbox',
                'visible' => true,
                'parent_class_name' => 'AdminChatpuff',
                'wording' => 'Inbox',
                'wording_domain' => 'Modules.Chatpuff.Admin',
            ],
            [
                'name' => 'Settings',
                'class_name' => 'AdminChatpuffSettings',
                'visible' => true,
                'parent_class_name' => 'AdminChatpuff',
                'wording' => 'Settings',
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
        if (!parent::install() || !$this->registerHook(self::STOREFRONT_HOOKS) || !$this->registerHook(self::BACK_OFFICE_HOOKS) || !$this->registerHook(self::KNOWLEDGE_HOOKS) || !Knowledge::installTable()) {
            return false;
        }
        self::removeRedundantMenuEntry();
        Settings::installationId();

        return true;
    }

    public function hookDisplayBeforeBodyClosingTag(): string
    {
        return $this->widgetTag();
    }

    /**
     * On every back-office page of a connected shop: a badge next to ChatPuff and Inbox in the
     * menu with what waits for the employee in ChatPuff (api-contract.md §9), kept fresh every
     * minute, and a chime when a chat starts waiting. The script asks the Inbox tab for a staff
     * token, so only employees who may open the inbox get one; an employee whose account is not
     * linked is left alone for an hour.
     */
    public function hookDisplayBackOfficeHeader(): string
    {
        // PrestaShop 8 and 9 register a module's tabs after install() returns, so the copy of the parent
        // entry is removed from the first back-office page, once per module version.
        if (Configuration::get(self::MENU_CLEANED) !== ApiClient::MODULE_VERSION && self::removeRedundantMenuEntry()) {
            Configuration::updateGlobalValue(self::MENU_CLEANED, ApiClient::MODULE_VERSION);
        }
        if (Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return '';
        }
        $idShop = (int) $this->context->shop->id;
        if (!Validate::isLoadedObject($this->context->employee) || Settings::connection($idShop) === null) {
            return '';
        }
        $idTab = (int) Tab::getIdFromClassName('AdminChatpuffInbox');
        if ($idTab === 0 || !Tab::checkTabRights($idTab)) {
            return '';
        }
        $api = new ApiClient();
        if ((new Pairing($api, $this->context->link))->isCopy($idShop)) {
            return '';
        }
        $setup = [
            'token_url' => $this->context->link->getAdminLink('AdminChatpuffInbox') . '&ajax=1&action=staffToken',
            'api' => $api->baseUrl(),
        ];

        return '<script type="application/json" id="chatpuff-badge-setup">' . json_encode($setup, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
            . '<script src="' . htmlspecialchars($this->getPathUri() . 'views/js/badge.js?v=' . ApiClient::MODULE_VERSION, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }

    /** @param array<string, mixed> $params 'object': the product */
    public function hookActionObjectProductAddAfter(array $params): void
    {
        $this->productChanged($params['object'] ?? null);
    }

    /** @param array<string, mixed> $params 'object': the product */
    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $this->productChanged($params['object'] ?? null);
    }

    /** @param array<string, mixed> $params 'object': the product */
    public function hookActionObjectProductDeleteAfter(array $params): void
    {
        $this->productChanged($params['object'] ?? null);
    }

    /**
     * A product's stock changed (an order, a return, the back office). Its text changes only when
     * it runs out or comes back, and nothing is sent otherwise.
     *
     * @param array<string, mixed> $params 'id_product'
     */
    public function hookActionUpdateQuantity(array $params): void
    {
        $this->noteProduct((int) ($params['id_product'] ?? 0));
    }

    /** @param array<string, mixed> $params 'object': the combination */
    public function hookActionObjectCombinationAddAfter(array $params): void
    {
        $this->partChanged($params['object'] ?? null);
    }

    /** @param array<string, mixed> $params 'object': the combination */
    public function hookActionObjectCombinationUpdateAfter(array $params): void
    {
        $this->partChanged($params['object'] ?? null);
    }

    /** @param array<string, mixed> $params 'object': the combination */
    public function hookActionObjectCombinationDeleteAfter(array $params): void
    {
        $this->partChanged($params['object'] ?? null);
    }

    /** @param array<string, mixed> $params 'object': the specific price */
    public function hookActionObjectSpecificPriceAddAfter(array $params): void
    {
        $this->partChanged($params['object'] ?? null);
    }

    /** @param array<string, mixed> $params 'object': the specific price */
    public function hookActionObjectSpecificPriceUpdateAfter(array $params): void
    {
        $this->partChanged($params['object'] ?? null);
    }

    /** @param array<string, mixed> $params 'object': the specific price */
    public function hookActionObjectSpecificPriceDeleteAfter(array $params): void
    {
        $this->partChanged($params['object'] ?? null);
    }

    /**
     * {widget name='chatpuff'} in a template, the displayChatPuff hook, or any other display hook
     * the module is transplanted to (Design > Positions). The chat always floats in a corner of the
     * page, wherever its script is.
     *
     * @param string $hookName
     * @param array<string, mixed> $configuration
     */
    public function renderWidget($hookName, array $configuration): string
    {
        return $this->widgetTag();
    }

    /**
     * @param string $hookName
     * @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    public function getWidgetVariables($hookName, array $configuration): array
    {
        return [];
    }

    /**
     * Some themes and page builders (Elementor layouts among them) never call a hook that could
     * carry the script: it then goes in just before </body> of the finished page. Not on the
     * maintenance (503) or restricted-country (403) pages.
     *
     * @param array<string, mixed> $params 'html': the page, by reference
     */
    public function hookActionOutputHTMLBefore(array $params): void
    {
        if (self::$widgetAdded || !isset($params['html']) || !is_string($params['html']) || in_array(http_response_code(), [403, 503], true)) {
            return;
        }
        $tag = $this->widgetTag();
        if ($tag === '') {
            return;
        }
        $position = strripos($params['html'], '</body>');
        $params['html'] = $position === false ? $params['html'] . $tag : substr_replace($params['html'], $tag, $position, 0);
    }

    /**
     * The chat widget's script for a storefront page of a connected shop (api-contract.md §7.1),
     * once per page. ChatPuff shows the chat only once the owner publishes it, and the script loads
     * async, so it never slows the page down or breaks it. Cloudflare's Rocket Loader leaves it
     * alone (data-cfasync).
     */
    private function widgetTag(): string
    {
        if (self::$widgetAdded) {
            return '';
        }
        $shop = $this->context->shop;
        $widget = Settings::widget((int) $shop->id);
        if ($widget === null) {
            return '';
        }
        // A copy of a connected shop, such as a staging site, must not show the live shop's chat.
        if (ShopDomain::normalize($shop->getBaseURL(true)) !== $widget['domain']) {
            return '';
        }

        self::$widgetAdded = true;
        $this->reportAfterResponse((int) $shop->id);

        return sprintf(
            '<script async data-cfasync="false" src="%s" data-shop="%s" data-locale="%s"%s%s%s></script>',
            htmlspecialchars((new ApiClient())->baseUrl() . '/widget/v1/loader.js', ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($widget['shop_id'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $this->context->language->iso_code, ENT_QUOTES, 'UTF-8'),
            $this->privacyAttribute((int) $shop->id),
            $this->customerTokenAttribute((int) $shop->id),
            $this->pageAttribute()
        );
    }

    /**
     * On a product page, the product it shows (its ID, name and address), so that the team and
     * the assistant know which one the customer is asking about (api-contract.md §8). The chat
     * sends it with the customer's messages.
     */
    private function pageAttribute(): string
    {
        $controller = $this->context->controller;
        if (!$controller instanceof ProductController) {
            return '';
        }
        $product = $controller->getProduct();
        if (!Validate::isLoadedObject($product)) {
            return '';
        }
        $idLang = (int) $this->context->language->id;
        $name = is_array($product->name) ? (string) ($product->name[$idLang] ?? reset($product->name)) : (string) $product->name;
        $name = trim(strip_tags($name));
        if ($name === '') {
            return '';
        }
        $page = ['product' => ['id' => (string) $product->id, 'name' => $name, 'url' => $this->context->link->getProductLink($product)]];

        return ' data-page="' . htmlspecialchars((string) json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '"';
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
        $finish = self::finisher();
        if ($finish === null) {
            return;
        }
        $now = time();
        $report = Settings::claimReport($idShop, $now);
        // The assistant's knowledge (api-contract.md §7.6): a slice of the shop's content per run, within a budget.
        $knowledge = Knowledge::claim($idShop, $now);
        // Products changed where no response could be finished early (an import from the command line).
        $changes = Knowledge::hasPending($idShop);
        if (!$report && !$knowledge && !$changes) {
            return;
        }
        $link = $this->context->link;
        register_shutdown_function(static function () use ($finish, $idShop, $link, $report, $knowledge, $changes): void {
            $finish();
            try {
                if ($report) {
                    (new Pairing(new ApiClient(), $link))->reportInstallation($idShop);
                }
                if ($changes) {
                    (new Knowledge(new ApiClient(), $link))->sendPending($idShop);
                }
                if ($knowledge) {
                    (new Knowledge(new ApiClient(), $link))->run($idShop, Knowledge::STOREFRONT_BUDGET);
                }
            } catch (Exception $exception) {
                // The next report is due in an hour; the chat works either way.
            }
        });
    }

    /**
     * How this server ends a response early, so that what follows it never delays the page; null
     * where it cannot.
     */
    private static function finisher(): ?callable
    {
        if (function_exists('fastcgi_finish_request')) {
            return static function (): void {
                fastcgi_finish_request();
            };
        }
        if (function_exists('litespeed_finish_request')) {
            return static function (): void {
                litespeed_finish_request();
            };
        }

        return null;
    }

    /** @param mixed $product */
    private function productChanged($product): void
    {
        if ($product instanceof Product) {
            $this->noteProduct((int) $product->id);
        }
    }

    /**
     * A combination or a specific price changed: its product, or every product for a specific price
     * of the whole catalog.
     *
     * @param mixed $part
     */
    private function partChanged($part): void
    {
        if (!$part instanceof Combination && !$part instanceof SpecificPrice) {
            return;
        }
        if ((int) $part->id_product > 0) {
            $this->noteProduct((int) $part->id_product);
        } elseif ($part instanceof SpecificPrice) {
            Knowledge::noteCatalog();
        }
    }

    /**
     * Notes the product and, once per request, sends what changed after the response is out
     * (api-contract.md §7.6), in every connected shop. Where a response cannot be finished early,
     * the next storefront page sends it.
     */
    private function noteProduct(int $idProduct): void
    {
        Knowledge::noteProduct($idProduct);
        if (self::$changesQueued) {
            return;
        }
        $finish = self::finisher();
        if ($finish === null) {
            return;
        }
        self::$changesQueued = true;
        $link = $this->context->link;
        register_shutdown_function(static function () use ($finish, $link): void {
            $finish();
            try {
                $knowledge = new Knowledge(new ApiClient(), $link);
                foreach (Settings::connectedShopIds() as $idShop) {
                    $knowledge->sendPending($idShop);
                }
            } catch (Exception $exception) {
                // The next pass sends what changed.
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
        Knowledge::dropTable();

        return parent::uninstall();
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminChatpuffSettings'));
    }

    /**
     * When a module declares a parent entry with children, PrestaShop makes the menu's parent a copy
     * of it with the suffix _MTR (a container without a controller) and keeps the declared entry, the
     * one with the controller, as its first child, so that its page stays reachable. ChatPuff's entry
     * only opens the inbox, so that child doubles the menu: it goes, and the container stays as the
     * parent of Inbox and Settings. Shops upgraded from 0.7 have the entry itself as the parent and
     * nothing doubled. The tabs are read with plain queries: Tab::getIdFromClassName() caches the
     * table for the request, before the tabs were registered.
     *
     * @return bool whether the menu is clean now
     */
    public static function removeRedundantMenuEntry(): bool
    {
        $db = Db::getInstance();
        $idContainer = (int) $db->getValue('SELECT id_tab FROM `' . _DB_PREFIX_ . 'tab` WHERE class_name = \'AdminChatpuff_MTR\'');
        if ($idContainer === 0) {
            return true;
        }
        $idEntry = (int) $db->getValue('SELECT id_tab FROM `' . _DB_PREFIX_ . 'tab` WHERE class_name = \'AdminChatpuff\' AND id_parent = ' . $idContainer);
        if ($idEntry === 0) {
            return true;
        }

        return (bool) (new Tab($idEntry))->delete();
    }

    /**
     * The name before 0.12.2, kept for the upgrade script of 0.8.0.
     */
    public static function removeDuplicatedParentTab(): void
    {
        self::removeRedundantMenuEntry();
    }
}
