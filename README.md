# ChatPuff for PrestaShop

Live chat for your PrestaShop shop: your customers chat from the storefront, and you answer from the PrestaShop back office, the [ChatPuff](https://chatpuff.com) dashboard or the ChatPuff native apps.

This module connects a shop to ChatPuff. It is deliberately thin: the chat widget and the back-office inbox are served by ChatPuff, so fixes reach every shop without a module update.

## Requirements

- PrestaShop 1.7.8 to 9.x
- PHP 7.4 to 8.5, with the cURL and JSON extensions (ext-sodium is used when available; otherwise the bundled `paragonie/sodium_compat` takes over)
- HTTPS on the shop, and outgoing HTTPS connections from the server to `api.chatpuff.com`

## Connecting a shop

1. Install the module and open **ChatPuff > Settings** in the back office (until 0.8.0 the page was under Customer Service).
2. Click **Connect to ChatPuff**. A ChatPuff page opens: sign in, or create a free account, and confirm the shop.
3. ChatPuff checks that the request really comes from your shop's domain, and the back office shows the shop as connected.

In a multistore, connect each shop separately. The chat widget stays hidden until you publish it from the ChatPuff dashboard. Customers who are logged in to your shop are recognised by the chat and do not type their name and email.

Choose your **privacy policy page** on the same screen, once the shop is connected. The chat links to it where it asks guests for their name and email.

The module reports its version and your PrestaShop and PHP versions to ChatPuff once an hour, after a storefront page has been sent to the visitor, so it never slows a page down. On servers without PHP-FPM or LiteSpeed it reports when the ChatPuff page of the back office is opened.

## The chat on your storefront

The chat is a button that floats in a corner of every storefront page. The module adds its script (it loads async, so it never slows a page down) in the first of these places, and only once per page:

1. the `displayBeforeBodyClosingTag` hook, which PrestaShop's themes call at the end of every page;
2. the `displayChatPuff` hook, or `{widget name='chatpuff'}`, for themes and page builders (Elementor layouts among them) that leave that hook out: put either in your theme's template, or attach ChatPuff to another hook in **Design > Positions**;
3. otherwise, just before `</body>` of the finished page, so the chat appears even when no hook carries it.

Where the script lands does not move the button. The chat is not added to the maintenance page.

On a product page, the tag names the product (its ID, name and address, `data-page`), and the chat sends it to ChatPuff with the customer's messages, so the team and the AI assistant know which product "is this one in stock?" is about. On other pages the chat sends the page's address and title.

The chat does not appear when:
- it is not published yet: the module's page says so, and an owner or admin publishes it from the shop's page in ChatPuff;
- a page cache module serves pages saved before ChatPuff was installed: clear its cache;
- the shop's address is not the one it was connected with (a copy of the shop, such as a staging site).

## Answering chats in the back office

**ChatPuff > Inbox** shows the ChatPuff inbox once the shop is connected. Each employee links their back-office account to their own ChatPuff account once: click **Link my ChatPuff account**, sign in to ChatPuff in the window that opens, and confirm. The employee must be a member of the shop's ChatPuff organization with access to the shop; an owner or admin grants it in ChatPuff. Employees who may open the ChatPuff tab in PrestaShop see the inbox; what they may do in it is decided by their role in ChatPuff.

From 0.9.0, a badge next to **ChatPuff** and **Inbox** in the menu shows, on every back-office page, how many chats wait for the employee in ChatPuff: the chats waiting for someone to take them, and the employee's own chats with an unread message from the customer. It is refreshed every minute, and a chime plays when a chat starts waiting (the inbox's **Sound alerts** switch turns it off). The badge appears for employees who may open the Inbox tab and have linked their ChatPuff account; an employee who has not is left alone for an hour before the module asks ChatPuff again.

## The AI assistant's knowledge

From 0.7.0 the module sends the shop's published products, categories and CMS pages to ChatPuff, so that the AI assistant can answer customers from them: for each language, the product's name, reference, category, price with tax, availability and descriptions; the category's name and description; the page's title and content. Nothing about customers, orders or prices of customer groups is sent. The module sends what changed about once an hour, after a storefront page has reached its visitor, a few dozen items at a time; a large catalog takes a few hours the first time. **ChatPuff > Settings** shows where it stands and has a **Synchronize now** button, which runs the synchronization step by step with a progress bar until it is complete (from 0.8.0). On the ChatPuff dashboard, the shop's Knowledge page lists what arrived, lets the merchant exclude anything, and takes the merchant's own questions and answers.

## Verifying orders in the chat

From 0.10.0 a customer can prove in the chat that an order is theirs before the team talks about it: they type the order reference (or the order number), and ChatPuff emails a code to the address on the order; a logged-in customer's own order is verified at once. To do that, ChatPuff asks the module about the order through its callback (`?action=order`), and the module answers only when the call is signed with ChatPuff's own key, was made within the last five minutes, and was never seen before. The answer names the order, its customer and the email address on it, nothing else, and only for an order of the shop that was asked about. Nothing changes in the shop, and no order data is stored at ChatPuff beyond the verification itself.

### Security

- The module creates its own Ed25519 key pair on your server. The private key never leaves the shop and is stored encrypted with your shop's cookie key; ChatPuff only receives the public key.
- Every call to ChatPuff is signed and can be sent only once. ChatPuff proves domain ownership by asking the module to sign a challenge on your shop's own address.
- A copy of a connected shop (for example a staging site on another address) does not use the live shop's connection. The module shows a warning and lets you disconnect the copy or connect it as a separate shop.
- Back-office access to the inbox uses short-lived tokens that ChatPuff checks on every request, and that reach only the shops connected from this PrestaShop. Matching email addresses never link accounts: the employee signs in to ChatPuff once to link them.
- Uninstalling disconnects the shops from ChatPuff. Their chat history stays in ChatPuff; your ChatPuff plan does not change.

## Troubleshooting

**"ChatPuff could not reach this shop to check it."** When you connect, ChatPuff calls `/module/chatpuff/callback` on your shop's own address to prove that the shop is yours. Something in front of the shop answered instead of the module:

- **Cloudflare.** Its bot protection answers with a challenge page (HTTP 403 or 503). Add a custom rule (Security > WAF > Custom rules, or Security rules in the newer dashboard) with the expression below and the action **Skip**. Tick "All remaining custom rules", and under the other components "Security Level" and "Browser Integrity Check". Place it first, save it, and connect again.

  ```
  (http.request.uri.path contains "/module/chatpuff/callback")
  ```

  Bot Fight Mode cannot be skipped by a rule: switch it off while you connect.
- **A password on the shop (common on staging copies), or another firewall.** Let requests to that address through while you connect.

Maintenance mode and country restrictions do not block the check: from version 0.5.1 the callback answers ChatPuff while the shop is closed to visitors.

The callback reveals nothing: it only answers while a connection is being confirmed, and only by signing ChatPuff's random challenge.

To test it from any server, call the address that the module's page shows after a failed connection (it may carry a language, such as `/el/`) with `?action=verify&challenge=test`. It should answer HTTP 400 with `{"code":"invalid_challenge"}`:

```bash
curl -i 'https://your-shop.example/el/module/chatpuff/callback?action=verify&challenge=test'
```

## Development

```bash
composer install
composer cs            # php-cs-fixer, PrestaShop coding standard
composer phpstan       # needs _PS_ROOT_DIR_ pointing at a PrestaShop installation
```

To connect a development shop to a local ChatPuff, add to the shop's `config/defines_custom.inc.php`:

```php
define('_CHATPUFF_API_URL_', 'http://localhost:8080');
```

CI checks PHP syntax from 7.4 to 8.5, the coding standard, and PHPStan against PrestaShop 8.2 and 9.1.

## Releases

1. Set the new version in `MODULE_VERSION` (`src/ApiClient.php`) and add `upgrade/upgrade-X.Y.Z.php`.
2. Push a tag `vX.Y.Z` with the same version.

The **Release** workflow builds `chatpuff-X.Y.Z.zip` (the files shops install, without development files, with the runtime dependencies) and publishes it as a GitHub release. Running the workflow by hand builds the zip as a download for testing, without a release.

## License

[Academic Free License 3.0](LICENSE.md) (AFL-3.0).
