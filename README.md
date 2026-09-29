# ChatPuff for PrestaShop

Live chat for your PrestaShop shop: your customers chat from the storefront, and you answer from the PrestaShop back office, the [ChatPuff](https://chatpuff.com) dashboard or the ChatPuff native apps.

This module connects a shop to ChatPuff. It is deliberately thin: the chat widget and the back-office inbox are served by ChatPuff, so fixes reach every shop without a module update.

## Requirements

- PrestaShop 1.7.8 to 9.x
- PHP 7.4 to 8.5, with the cURL and JSON extensions (ext-sodium is used when available; otherwise the bundled `paragonie/sodium_compat` takes over)
- HTTPS on the shop, and outgoing HTTPS connections from the server to `api.chatpuff.com`

## Connecting a shop

1. Install the module and open **Customer Service > ChatPuff** in the back office.
2. Click **Connect to ChatPuff**. A ChatPuff page opens: sign in, or create a free account, and confirm the shop.
3. ChatPuff checks that the request really comes from your shop's domain, and the back office shows the shop as connected.

In a multistore, connect each shop separately. The chat widget stays hidden until you publish it from the ChatPuff dashboard. Customers who are logged in to your shop are recognised by the chat and do not type their name and email.

Choose your **privacy policy page** on the same screen, once the shop is connected. The chat links to it where it asks guests for their name and email.

The module reports its version and your PrestaShop and PHP versions to ChatPuff once an hour, after a storefront page has been sent to the visitor, so it never slows a page down. On servers without PHP-FPM or LiteSpeed it reports when the ChatPuff page of the back office is opened.

## Answering chats in the back office

**Customer Service > ChatPuff** shows the ChatPuff inbox once the shop is connected. Each employee links their back-office account to their own ChatPuff account once: click **Link my ChatPuff account**, sign in to ChatPuff in the window that opens, and confirm. The employee must be a member of the shop's ChatPuff organization with access to the shop; an owner or admin grants it in ChatPuff. Employees who may open the ChatPuff tab in PrestaShop see the inbox; what they may do in it is decided by their role in ChatPuff.

### Security

- The module creates its own Ed25519 key pair on your server. The private key never leaves the shop and is stored encrypted with your shop's cookie key; ChatPuff only receives the public key.
- Every call to ChatPuff is signed and can be sent only once. ChatPuff proves domain ownership by asking the module to sign a challenge on your shop's own address.
- A copy of a connected shop (for example a staging site on another address) does not use the live shop's connection. The module shows a warning and lets you disconnect the copy or connect it as a separate shop.
- Back-office access to the inbox uses short-lived tokens that ChatPuff checks on every request, and that reach only the shops connected from this PrestaShop. Matching email addresses never link accounts: the employee signs in to ChatPuff once to link them.
- Uninstalling disconnects the shops from ChatPuff. Their chat history stays in ChatPuff; your ChatPuff plan does not change.

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
