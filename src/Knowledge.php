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

namespace ChatPuff\PrestaShop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Sends the shop's published products, categories and CMS pages to ChatPuff, for the AI assistant's
 * knowledge (api-contract.md §7.6, capability knowledge_sync). A pass walks each kind in order,
 * language by language, within a time budget, and continues at the next run until it is complete;
 * only sources whose title or text changed since they were last sent travel, and each kind ends
 * with the inventory of what is published. Nothing about customers is ever sent.
 */
final class Knowledge
{
    public const TABLE = 'chatpuff_knowledge';
    public const CURSOR = 'CHATPUFF_KNOWLEDGE_CURSOR';
    public const CLAIMED_AT = 'CHATPUFF_KNOWLEDGE_AT';
    /** Seconds between runs: hourly once a pass is complete, five minutes while one is in progress. */
    public const INTERVAL_COMPLETE = 3600;
    public const INTERVAL_IN_PROGRESS = 300;
    /** Seconds a run may take after a storefront page was sent, from the module's page, from the admin button, and per step of the page's progress bar. */
    public const STOREFRONT_BUDGET = 20;
    public const ADMIN_BUDGET = 40;
    public const PAGE_BUDGET = 5;
    public const STEP_BUDGET = 8;
    private const KINDS = ['product', 'category', 'page'];
    /** Items read per database page, and sent per request (the contract allows 100). */
    private const PAGE = 40;
    /** Identifiers an inventory may list (the contract's limit); a larger kind keeps what ChatPuff has. */
    private const MAX_INVENTORY = 100000;
    private const MAX_TEXT = 60000;

    /** @var ApiClient */
    private $api;
    /** @var \Link */
    private $link;

    public function __construct(ApiClient $api, \Link $link)
    {
        $this->api = $api;
        $this->link = $link;
    }

    public static function installTable(): bool
    {
        return (bool) \Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` ('
            . '`id_shop` INT UNSIGNED NOT NULL, `kind` VARCHAR(16) NOT NULL, `locale` VARCHAR(16) NOT NULL, `external_id` VARCHAR(64) NOT NULL, '
            . '`content_hash` CHAR(64) NOT NULL, `sent_at` DATETIME NOT NULL, '
            . 'PRIMARY KEY (`id_shop`, `kind`, `locale`, `external_id`)'
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public static function dropTable(): bool
    {
        return (bool) \Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    /**
     * Whether a run is due for this shop. Claiming it at once keeps the other visitors of the same
     * moment from running it too.
     */
    public static function claim(int $idShop, int $now): bool
    {
        $interval = self::cursor($idShop)['kind'] === null ? self::INTERVAL_COMPLETE : self::INTERVAL_IN_PROGRESS;
        $last = (int) \Configuration::get(self::CLAIMED_AT, null, null, $idShop);
        if ($now - $last < $interval) {
            return false;
        }
        \Configuration::updateValue(self::CLAIMED_AT, $now, false, (int) \Shop::getGroupFromShop($idShop, true), $idShop);

        return true;
    }

    /** Keeps the background runs away for a while: the page's own steps are running. */
    public static function touch(int $idShop, int $now): void
    {
        \Configuration::updateValue(self::CLAIMED_AT, $now, false, (int) \Shop::getGroupFromShop($idShop, true), $idShop);
    }

    /**
     * How far the pass is, for the page's progress bar: the items walked so far (every kind before
     * the current one, and the current one up to the last ID sent) out of all the items published.
     *
     * @return array{done: int, total: int}
     */
    public static function progress(int $idShop): array
    {
        $cursor = self::cursor($idShop);
        $done = 0;
        $total = 0;
        $reached = $cursor['kind'] === null;
        foreach (self::KINDS as $kind) {
            $count = self::count($idShop, $kind);
            $total += $count;
            if ($reached) {
                $done += $count;
            } elseif ($kind === $cursor['kind']) {
                $done += self::count($idShop, $kind, $cursor['last_id']);
                $reached = true;
            } else {
                $done += $count;
            }
        }

        return ['done' => min($done, $total), 'total' => $total];
    }

    /**
     * Where the pass stands: the kind being walked (null between passes) and the last ID sent in
     * it, when the pass started and when the last one completed, what it checked and sent, and
     * the code of the last error.
     *
     * @return array{kind: string|null, last_id: int, started_at: int|null, completed_at: int|null, checked: int, sent: int, error: string|null}
     */
    public static function cursor(int $idShop): array
    {
        $raw = \Configuration::get(self::CURSOR, null, null, $idShop);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($data) ? $data : [];

        return [
            'kind' => in_array($data['kind'] ?? null, self::KINDS, true) ? (string) $data['kind'] : null,
            'last_id' => (int) ($data['last_id'] ?? 0),
            'started_at' => isset($data['started_at']) ? (int) $data['started_at'] : null,
            'completed_at' => isset($data['completed_at']) ? (int) $data['completed_at'] : null,
            'checked' => (int) ($data['checked'] ?? 0),
            'sent' => (int) ($data['sent'] ?? 0),
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    /**
     * Runs the pass for up to the budget, then saves where it stands.
     *
     * @return array{kind: string|null, last_id: int, started_at: int|null, completed_at: int|null, checked: int, sent: int, error: string|null}
     */
    public function run(int $idShop, int $budgetSeconds): array
    {
        $cursor = self::cursor($idShop);
        if (Settings::connection($idShop) === null) {
            return $cursor;
        }
        if ($cursor['kind'] === null) {
            $cursor = ['kind' => self::KINDS[0], 'last_id' => 0, 'started_at' => time(), 'completed_at' => $cursor['completed_at'], 'checked' => 0, 'sent' => 0, 'error' => null];
        }
        $deadline = microtime(true) + $budgetSeconds;
        $languages = $this->languages($idShop);
        $pairing = new Pairing($this->api, $this->link);
        try {
            while ($cursor['kind'] !== null && microtime(true) < $deadline) {
                $kind = (string) $cursor['kind'];
                $ids = $this->ids($idShop, $kind, $cursor['last_id']);
                if ($ids === []) {
                    $all = $this->ids($idShop, $kind, 0, true);
                    if (count($all) <= self::MAX_INVENTORY) {
                        foreach ($languages as $language) {
                            $pairing->send($idShop, 'PUT', '/integration/v1/knowledge/inventory', ['kind' => $kind, 'locale' => $language['locale'], 'external_ids' => array_map('strval', $all)]);
                            $this->forgetMissing($idShop, $kind, $language['locale'], $all);
                        }
                    }
                    $next = (int) array_search($kind, self::KINDS, true) + 1;
                    $cursor['kind'] = $next < count(self::KINDS) ? self::KINDS[$next] : null;
                    $cursor['last_id'] = 0;
                    if ($cursor['kind'] === null) {
                        $cursor['completed_at'] = time();
                    }
                    $this->saveCursor($idShop, $cursor);
                    continue;
                }
                foreach ($languages as $language) {
                    $sources = $this->sources($idShop, $kind, $ids, $language);
                    $cursor['checked'] += count($sources);
                    $changed = $this->changed($idShop, $kind, $language['locale'], $sources);
                    if ($changed !== []) {
                        $pairing->send($idShop, 'PUT', '/integration/v1/knowledge/sources', ['sources' => array_map(static function (array $source): array {
                            unset($source['hash']);

                            return $source;
                        }, $changed)]);
                        $this->remember($idShop, $kind, $language['locale'], $changed);
                        $cursor['sent'] += count($changed);
                    }
                }
                $cursor['last_id'] = max($ids);
                $cursor['error'] = null;
                $this->saveCursor($idShop, $cursor);
            }
        } catch (ApiException $exception) {
            $cursor['error'] = $exception->getProblemCode();
            $this->saveCursor($idShop, $cursor);
        }

        return $cursor;
    }

    /**
     * @param array{kind: string|null, last_id: int, started_at: int|null, completed_at: int|null, checked: int, sent: int, error: string|null} $cursor
     */
    private function saveCursor(int $idShop, array $cursor): void
    {
        \Configuration::updateValue(self::CURSOR, (string) json_encode($cursor), false, (int) \Shop::getGroupFromShop($idShop, true), $idShop);
    }

    /**
     * @return list<array{id_lang: int, locale: string, tag: string}> the shop's active languages: the ISO code ChatPuff gets, and the locale for translations
     */
    private function languages(int $idShop): array
    {
        $languages = [];
        foreach (\Language::getLanguages(true, $idShop) as $language) {
            if (!is_array($language)) {
                continue;
            }
            $languages[] = ['id_lang' => (int) $language['id_lang'], 'locale' => strtolower((string) $language['iso_code']), 'tag' => (string) ($language['locale'] ?? $language['iso_code'])];
        }

        return $languages;
    }

    /**
     * The IDs of a kind that the shop publishes, after a given one, in order; all of them for the inventory.
     *
     * @return list<int>
     */
    private function ids(int $idShop, string $kind, int $after, bool $all = false): array
    {
        [$from, $id] = self::published($idShop, $kind);
        $rows = \Db::getInstance()->executeS('SELECT ' . $id . ' AS id ' . $from . ' AND ' . $id . ' > ' . $after . ' ORDER BY ' . $id . ' ASC' . ($all ? '' : ' LIMIT ' . self::PAGE));

        return is_array($rows) ? array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $rows) : [];
    }

    /** How many of a kind the shop publishes, up to an ID when one is given. */
    private static function count(int $idShop, string $kind, ?int $upTo = null): int
    {
        [$from, $id] = self::published($idShop, $kind);

        return (int) \Db::getInstance()->getValue('SELECT COUNT(*) ' . $from . ($upTo === null ? '' : ' AND ' . $id . ' <= ' . (int) $upTo));
    }

    /**
     * The FROM and WHERE clauses of what the shop publishes of a kind, and the ID column.
     *
     * @return array{0: string, 1: string}
     */
    private static function published(int $idShop, string $kind): array
    {
        switch ($kind) {
            case 'product':
                return [
                    'FROM `' . _DB_PREFIX_ . 'product` p'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $idShop
                    . " WHERE ps.active = 1 AND ps.visibility IN ('both', 'catalog', 'search')",
                    'p.id_product',
                ];
            case 'category':
                return [
                    'FROM `' . _DB_PREFIX_ . 'category` c'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop
                    . ' WHERE c.active = 1 AND c.id_parent <> 0 AND c.id_category <> ' . (int) (new \Shop($idShop))->id_category,
                    'c.id_category',
                ];
            default:
                return [
                    'FROM `' . _DB_PREFIX_ . 'cms` c'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs ON cs.id_cms = c.id_cms AND cs.id_shop = ' . $idShop
                    . ' WHERE c.active = 1',
                    'c.id_cms',
                ];
        }
    }

    /**
     * The sources of a kind in one language, as the contract wants them, with their hash.
     *
     * @param list<int> $ids
     * @param array{id_lang: int, locale: string, tag: string} $language
     *
     * @return list<array{kind: string, external_id: string, locale: string, title: string, url: string|null, text: string, hash: string}>
     */
    private function sources(int $idShop, string $kind, array $ids, array $language): array
    {
        $idLang = $language['id_lang'];
        $in = implode(',', $ids);
        $sources = [];
        switch ($kind) {
            case 'product':
                $rows = \Db::getInstance()->executeS(
                    'SELECT p.id_product, p.reference, ps.id_category_default, pl.name, pl.description_short, pl.description, cl.name AS category'
                    . ' FROM `' . _DB_PREFIX_ . 'product` p'
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $idShop
                    . ' INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_shop = ' . $idShop . ' AND pl.id_lang = ' . $idLang
                    . ' LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.id_category = ps.id_category_default AND cl.id_shop = ' . $idShop . ' AND cl.id_lang = ' . $idLang
                    . ' WHERE p.id_product IN (' . $in . ') ORDER BY p.id_product ASC'
                );
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $id = (int) $row['id_product'];
                    $lines = [];
                    if (trim((string) $row['reference']) !== '') {
                        $lines[] = $this->label('Reference', $language) . ': ' . trim((string) $row['reference']);
                    }
                    if (trim((string) $row['category']) !== '') {
                        $lines[] = $this->label('Category', $language) . ': ' . trim((string) $row['category']);
                    }
                    $lines[] = $this->label('Price', $language) . ': ' . $this->price($id);
                    $lines[] = $this->availability($idShop, $id, $language);
                    $text = implode("\n", $lines) . "\n\n" . self::plainText((string) $row['description_short']) . "\n\n" . self::plainText((string) $row['description']);
                    $sources[] = $this->source($kind, (string) $id, $language['locale'], (string) $row['name'], $this->link->getProductLink($id, null, null, null, $idLang, $idShop), $text);
                }
                break;
            case 'category':
                $rows = \Db::getInstance()->executeS(
                    'SELECT cl.id_category, cl.name, cl.description FROM `' . _DB_PREFIX_ . 'category_lang` cl'
                    . ' WHERE cl.id_shop = ' . $idShop . ' AND cl.id_lang = ' . $idLang . ' AND cl.id_category IN (' . $in . ') ORDER BY cl.id_category ASC'
                );
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $id = (int) $row['id_category'];
                    $sources[] = $this->source($kind, (string) $id, $language['locale'], (string) $row['name'], $this->link->getCategoryLink($id, null, $idLang, null, $idShop), self::plainText((string) $row['description']));
                }
                break;
            default:
                $rows = \Db::getInstance()->executeS(
                    'SELECT cl.id_cms, cl.meta_title, cl.content FROM `' . _DB_PREFIX_ . 'cms_lang` cl'
                    . ' WHERE cl.id_shop = ' . $idShop . ' AND cl.id_lang = ' . $idLang . ' AND cl.id_cms IN (' . $in . ') ORDER BY cl.id_cms ASC'
                );
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $id = (int) $row['id_cms'];
                    $sources[] = $this->source($kind, (string) $id, $language['locale'], (string) $row['meta_title'], $this->link->getCMSLink($id, null, true, $idLang, $idShop), self::plainText((string) $row['content']));
                }
        }

        return array_values(array_filter($sources));
    }

    /**
     * @return array{kind: string, external_id: string, locale: string, title: string, url: string|null, text: string, hash: string}|null null without a title
     */
    private function source(string $kind, string $externalId, string $locale, string $title, string $url, string $text): ?array
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if ($title === '') {
            return null;
        }
        $title = mb_substr($title, 0, 300);
        $text = trim(preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text);
        $text = mb_substr($text, 0, self::MAX_TEXT);

        return [
            'kind' => $kind,
            'external_id' => $externalId,
            'locale' => $locale,
            'title' => $title,
            'url' => $url !== '' ? mb_substr($url, 0, 1000) : null,
            'text' => $text,
            'hash' => hash('sha256', $title . "\n" . $text),
        ];
    }

    /**
     * The sources whose hash differs from what was last sent.
     *
     * @param list<array{kind: string, external_id: string, locale: string, title: string, url: string|null, text: string, hash: string}> $sources
     *
     * @return list<array{kind: string, external_id: string, locale: string, title: string, url: string|null, text: string, hash: string}>
     */
    private function changed(int $idShop, string $kind, string $locale, array $sources): array
    {
        if ($sources === []) {
            return [];
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT external_id, content_hash FROM `' . _DB_PREFIX_ . self::TABLE . '`'
            . ' WHERE id_shop = ' . $idShop . " AND kind = '" . pSQL($kind) . "' AND locale = '" . pSQL($locale) . "'"
            . " AND external_id IN ('" . implode("','", array_map('pSQL', array_column($sources, 'external_id'))) . "')"
        );
        $known = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $known[(string) $row['external_id']] = (string) $row['content_hash'];
        }

        return array_values(array_filter($sources, static function (array $source) use ($known): bool {
            return ($known[$source['external_id']] ?? null) !== $source['hash'];
        }));
    }

    /**
     * @param list<array{kind: string, external_id: string, locale: string, title: string, url: string|null, text: string, hash: string}> $sources
     */
    private function remember(int $idShop, string $kind, string $locale, array $sources): void
    {
        $values = [];
        foreach ($sources as $source) {
            $values[] = '(' . $idShop . ", '" . pSQL($kind) . "', '" . pSQL($locale) . "', '" . pSQL($source['external_id']) . "', '" . pSQL($source['hash']) . "', NOW())";
        }
        \Db::getInstance()->execute('REPLACE INTO `' . _DB_PREFIX_ . self::TABLE . '` (id_shop, kind, locale, external_id, content_hash, sent_at) VALUES ' . implode(',', $values));
    }

    /**
     * Forgets what the shop no longer publishes, so that an item that returns is sent again.
     *
     * @param list<int> $published
     */
    private function forgetMissing(int $idShop, string $kind, string $locale, array $published): void
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_shop = ' . $idShop . " AND kind = '" . pSQL($kind) . "' AND locale = '" . pSQL($locale) . "'";
        if ($published !== []) {
            $sql .= ' AND external_id NOT IN (' . implode(',', array_map(static function (int $id): string {
                return "'" . $id . "'";
            }, $published)) . ')';
        }
        \Db::getInstance()->execute($sql);
    }

    /** The price customers see: with tax, in the context's currency. */
    private function price(int $idProduct): string
    {
        $price = (float) \Product::getPriceStatic($idProduct, true, null, 2);
        $context = \Context::getContext();
        $currency = $context->currency;
        $iso = $currency !== null ? (string) $currency->iso_code : '';
        try {
            if (method_exists($context, 'getCurrentLocale') && $iso !== '') {
                return (string) $context->getCurrentLocale()->formatPrice($price, $iso);
            }
        } catch (\Exception $exception) {
            // The plain number below.
        }

        return number_format($price, 2, '.', '') . ($iso !== '' ? ' ' . $iso : '');
    }

    /**
     * @param array{id_lang: int, locale: string, tag: string} $language
     */
    private function availability(int $idShop, int $idProduct, array $language): string
    {
        $quantity = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, null, $idShop);

        return $this->label($quantity > 0 ? 'In stock' : 'Out of stock', $language);
    }

    /**
     * A label in the shop's language, from PrestaShop's own theme translations; English when they lack it.
     *
     * @param array{id_lang: int, locale: string, tag: string} $language
     */
    private function label(string $english, array $language): string
    {
        try {
            $translated = \Context::getContext()->getTranslator()->trans($english, [], 'Shop.Theme.Catalog', $language['tag']);

            return $translated !== '' ? $translated : $english;
        } catch (\Exception $exception) {
            return $english;
        }
    }

    /** HTML as the text a customer reads: blocks become lines, tags go, entities are decoded. */
    public static function plainText(string $html): string
    {
        $text = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6]|/tr|/table|/blockquote)\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
