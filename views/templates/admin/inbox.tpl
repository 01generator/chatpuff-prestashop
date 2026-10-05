{**
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
 *}
{if $chatpuff.state == 'connected' && isset($chatpuff.inbox)}
  <div class="panel chatpuff-inbox-panel">
    <div class="panel-heading">
      <img src="{$chatpuff.logo_url|escape:'html':'UTF-8'}" alt="" width="16" height="16"> {l s='ChatPuff inbox' d='Modules.Chatpuff.Admin'}
    </div>
    <div class="chatpuff-backoffice"
         data-chatpuff-backoffice
         data-token-url="{$chatpuff.inbox.token_url|escape:'html':'UTF-8'}"
         data-link-url="{$chatpuff.inbox.link_url|escape:'html':'UTF-8'}"
         data-api="{$chatpuff.inbox.api|escape:'html':'UTF-8'}"
         data-locale="{$chatpuff.inbox.locale|escape:'html':'UTF-8'}"
         data-order-url="{$chatpuff.inbox.order_url|escape:'html':'UTF-8'}"
         data-popup-blocked="{l s='Your browser blocked the ChatPuff window. Allow pop-ups for this back office and try again.' d='Modules.Chatpuff.Admin'}"
         data-failed="{l s='The ChatPuff inbox could not be loaded. Check your internet connection and reload the page.' d='Modules.Chatpuff.Admin'}">
      <p class="text-muted" data-chatpuff-when="loading">
        <i class="icon-refresh icon-spin"></i> {l s='Loading the inbox…' d='Modules.Chatpuff.Admin'}
      </p>
      <div data-chatpuff-when="not_linked" hidden>
        <h4>{l s='Link your ChatPuff account' d='Modules.Chatpuff.Admin'}</h4>
        <p>{l s='To answer chats here, link your back-office account to your ChatPuff account once. A ChatPuff window opens where you sign in and confirm.' d='Modules.Chatpuff.Admin'}</p>
        <button type="button" class="btn btn-primary" data-chatpuff-link>{l s='Link my ChatPuff account' d='Modules.Chatpuff.Admin'}</button>
      </div>
      <p class="text-muted" data-chatpuff-when="linking" hidden>
        <i class="icon-refresh icon-spin"></i> {l s='Waiting for you to confirm in the ChatPuff window…' d='Modules.Chatpuff.Admin'}
      </p>
      <div data-chatpuff-when="no_access" hidden>
        <div class="alert alert-warning">{l s='Your ChatPuff account has no access to this shop\'s chats. Ask an owner or admin of your ChatPuff organization to give you access, or link another account.' d='Modules.Chatpuff.Admin'}</div>
        <button type="button" class="btn btn-default" data-chatpuff-link>{l s='Link another account' d='Modules.Chatpuff.Admin'}</button>
      </div>
      <div class="alert alert-danger" data-chatpuff-when="error" hidden></div>
      <div class="chatpuff-inbox" data-chatpuff-when="ready" hidden></div>
      <noscript><p>{l s='The inbox needs JavaScript.' d='Modules.Chatpuff.Admin'}</p></noscript>
    </div>
  </div>
{/if}
{if $chatpuff.state != 'connected'}
  <div class="panel chatpuff-panel">
    <div class="panel-heading">
      <img src="{$chatpuff.logo_url|escape:'html':'UTF-8'}" alt="" width="16" height="16"> {l s='ChatPuff inbox' d='Modules.Chatpuff.Admin'}
    </div>
    {if $chatpuff.state == 'select_shop'}
      <p>{l s='Select a single shop at the top of the page. Each shop is connected to ChatPuff separately.' d='Modules.Chatpuff.Admin'}</p>
    {else}
      <p>{l s='Connect this shop to ChatPuff first.' d='Modules.Chatpuff.Admin'} <a href="{$chatpuff.settings_url|escape:'html':'UTF-8'}">{l s='Open the settings' d='Modules.Chatpuff.Admin'}</a></p>
    {/if}
  </div>
{/if}
