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
<div class="panel chatpuff-panel">
  <div class="panel-heading">
    <img src="{$chatpuff.logo_url|escape:'html':'UTF-8'}" alt="" width="16" height="16"> ChatPuff
  </div>

  {if !$chatpuff.https_enabled}
    <div class="alert alert-warning">{l s='HTTPS is not enabled on this shop. ChatPuff needs HTTPS to check the shop when you connect it.' d='Modules.Chatpuff.Admin'}</div>
  {/if}
  {if $chatpuff.error}
    <div class="alert alert-danger">{$chatpuff.error|escape:'html':'UTF-8'}</div>
  {/if}

  {if $chatpuff.state == 'select_shop'}
    <p>{l s='Select a single shop at the top of the page. Each shop is connected to ChatPuff separately.' d='Modules.Chatpuff.Admin'}</p>

  {elseif $chatpuff.state == 'connected' || $chatpuff.state == 'just_connected'}
    <div class="alert alert-success">{l s='This shop is connected to ChatPuff.' d='Modules.Chatpuff.Admin'}</div>
    {if isset($chatpuff.status)}
      <dl class="chatpuff-details">
        <dt>{l s='Organization' d='Modules.Chatpuff.Admin'}</dt>
        <dd>{$chatpuff.status.organization_name|escape:'html':'UTF-8'}</dd>
        <dt>{l s='Shop' d='Modules.Chatpuff.Admin'}</dt>
        <dd>{$chatpuff.status.shop_name|escape:'html':'UTF-8'} ({$chatpuff.domain|escape:'html':'UTF-8'})</dd>
        <dt>{l s='Chat widget' d='Modules.Chatpuff.Admin'}</dt>
        <dd>{if $chatpuff.status.widget_published}{l s='Published' d='Modules.Chatpuff.Admin'}{else}{l s='Not published yet: publish it from the ChatPuff dashboard when you are ready.' d='Modules.Chatpuff.Admin'}{/if}</dd>
      </dl>
    {/if}
    <p>
      <a href="{$chatpuff.dashboard_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener" class="btn btn-primary">{l s='Open the ChatPuff dashboard' d='Modules.Chatpuff.Admin'}</a>
    </p>
    {if isset($chatpuff.privacy)}
      <form method="post" action="{$chatpuff.admin_url|escape:'html':'UTF-8'}" class="chatpuff-privacy">
        <label for="chatpuff-privacy-cms">{l s='Privacy policy page' d='Modules.Chatpuff.Admin'}</label>
        <div class="chatpuff-privacy-row">
          <select id="chatpuff-privacy-cms" name="chatpuff_privacy_cms" class="form-control">
            <option value="0">{l s='None' d='Modules.Chatpuff.Admin'}</option>
            {foreach $chatpuff.privacy.pages as $page}
              <option value="{$page.id_cms|intval}"{if $page.id_cms == $chatpuff.privacy.selected} selected{/if}>{$page.title|escape:'html':'UTF-8'}</option>
            {/foreach}
          </select>
          <button type="submit" name="chatpuffPrivacy" value="1" class="btn btn-default">{l s='Save' d='Modules.Chatpuff.Admin'}</button>
        </div>
        <p class="help-block">{l s='The chat links to this page where it asks customers for their name and email.' d='Modules.Chatpuff.Admin'}</p>
      </form>
    {/if}
    {if isset($chatpuff.knowledge)}
      <div class="chatpuff-knowledge">
        <h4>{l s='Knowledge for the AI assistant' d='Modules.Chatpuff.Admin'}</h4>
        <p class="help-block">{l s='The module sends this shop\'s published products, categories and pages to ChatPuff every hour, so the assistant can answer from them. Choose what it may use on the ChatPuff dashboard; nothing about customers is sent.' d='Modules.Chatpuff.Admin'}</p>
        <p>
          {if $chatpuff.knowledge.completed_at}
            {l s='Last complete synchronization: %date%.' sprintf=['%date%' => $chatpuff.knowledge.completed_at] d='Modules.Chatpuff.Admin'}
          {else}
            {l s='Not synchronized yet.' d='Modules.Chatpuff.Admin'}
          {/if}
          {if $chatpuff.knowledge.in_progress}
            {l s='A synchronization is in progress and continues with every visit to the shop.' d='Modules.Chatpuff.Admin'}
          {/if}
          {if $chatpuff.knowledge.error}
            <span class="text-danger">{l s='The last attempt failed (%code%); it is retried automatically.' sprintf=['%code%' => $chatpuff.knowledge.error] d='Modules.Chatpuff.Admin'}</span>
          {/if}
        </p>
        <form method="post" action="{$chatpuff.admin_url|escape:'html':'UTF-8'}" class="chatpuff-inline">
          <button type="submit" name="chatpuffSyncKnowledge" value="1" class="btn btn-default">{l s='Synchronize now' d='Modules.Chatpuff.Admin'}</button>
        </form>
      </div>
    {/if}
    <form method="post" action="{$chatpuff.admin_url|escape:'html':'UTF-8'}" class="chatpuff-inline" data-chatpuff-confirm="{l s='Disconnect this shop from ChatPuff? Its chat history stays in ChatPuff.' d='Modules.Chatpuff.Admin'}">
      <button type="submit" name="chatpuffDisconnect" value="1" class="btn btn-default">{l s='Disconnect' d='Modules.Chatpuff.Admin'}</button>
    </form>

  {elseif $chatpuff.state == 'copy'}
    <div class="alert alert-warning">
      {l s='This shop\'s address (%current%) is not the one it was connected with (%connected%). It looks like a copy of your shop, such as a staging site, so the connection is paused here: a copy must never act for your live shop.' sprintf=['%current%' => $chatpuff.domain, '%connected%' => $chatpuff.connected_domain] d='Modules.Chatpuff.Admin'}
    </div>
    <p>{l s='Disconnecting this copy leaves your live shop connected. You can then connect this copy as a separate shop.' d='Modules.Chatpuff.Admin'}</p>
    <form method="post" action="{$chatpuff.admin_url|escape:'html':'UTF-8'}">
      <button type="submit" name="chatpuffForgetCopy" value="1" class="btn btn-default">{l s='Disconnect this copy' d='Modules.Chatpuff.Admin'}</button>
    </form>

  {elseif $chatpuff.state == 'pending'}
    <h4>{l s='Confirm the connection in ChatPuff' d='Modules.Chatpuff.Admin'}</h4>
    <p>{l s='Sign in to ChatPuff, or create a free account, and confirm this shop. This page updates by itself.' d='Modules.Chatpuff.Admin'}</p>
    <p>
      <a href="{$chatpuff.confirmation_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener" class="btn btn-primary">{l s='Open ChatPuff to confirm' d='Modules.Chatpuff.Admin'}</a>
    </p>
    <p class="text-muted" data-chatpuff-poll="{$chatpuff.poll_url|escape:'html':'UTF-8'}">
      <i class="icon-refresh icon-spin"></i> {l s='Waiting for your confirmation…' d='Modules.Chatpuff.Admin'}
    </p>
    <form method="post" action="{$chatpuff.admin_url|escape:'html':'UTF-8'}" data-chatpuff-connect="{$chatpuff.start_url|escape:'html':'UTF-8'}">
      <button type="submit" name="chatpuffConnect" value="1" class="btn btn-link">{l s='Start again' d='Modules.Chatpuff.Admin'}</button>
    </form>

  {else}
    {if $chatpuff.state == 'rejected'}
      <div class="alert alert-danger">
        {if $chatpuff.rejection == 'domain_verification_failed'}
          {l s='ChatPuff could not reach this shop to check it. Make sure the shop is online over HTTPS and not in maintenance mode, and that no firewall blocks ChatPuff. Then try again.' d='Modules.Chatpuff.Admin'}
        {elseif $chatpuff.rejection == 'domain_already_claimed'}
          {l s='This shop address is already connected to another ChatPuff account. Contact ChatPuff support to move it.' d='Modules.Chatpuff.Admin'}
        {elseif $chatpuff.rejection == 'installation_in_other_organization'}
          {l s='This PrestaShop is already connected to another ChatPuff organization. Contact ChatPuff support to move it.' d='Modules.Chatpuff.Admin'}
        {elseif $chatpuff.rejection == 'domain_changed'}
          {l s='This shop was connected under another address. Contact ChatPuff support to change it.' d='Modules.Chatpuff.Admin'}
        {elseif $chatpuff.rejection == 'pairing_cancelled'}
          {l s='The connection was cancelled in ChatPuff. You can start again at any time.' d='Modules.Chatpuff.Admin'}
        {else}
          {l s='The shop was not connected.' d='Modules.Chatpuff.Admin'}
        {/if}
      </div>
      {if $chatpuff.rejection == 'domain_verification_failed'}
        <div class="chatpuff-help">
          <p>{l s='ChatPuff checks the shop by calling this address:' d='Modules.Chatpuff.Admin'}<br><code>{$chatpuff.callback_url|escape:'html':'UTF-8'}</code></p>
          <p>{l s='Behind Cloudflare, the bot protection often blocks this check. In Cloudflare, add a custom rule (Security > WAF > Custom rules, or Security rules in the newer dashboard) with this expression:' d='Modules.Chatpuff.Admin'}</p>
          <pre class="chatpuff-rule">(http.request.uri.path contains "/module/chatpuff/callback")</pre>
          <p>{l s='Choose the action Skip. Tick "All remaining custom rules", and under the other components "Security Level" and "Browser Integrity Check". Place the rule first, save it, then connect again.' d='Modules.Chatpuff.Admin'}</p>
          <p>{l s='Cloudflare\'s Bot Fight Mode cannot be skipped by a rule: switch it off while you connect. With another firewall, or a password on the shop, let requests to this address through.' d='Modules.Chatpuff.Admin'}</p>
        </div>
      {/if}
    {elseif $chatpuff.state == 'expired'}
      <div class="alert alert-warning">{l s='The confirmation link expired before it was used. Please start again.' d='Modules.Chatpuff.Admin'}</div>
    {/if}
    <h4>{l s='Connect this shop to ChatPuff' d='Modules.Chatpuff.Admin'}</h4>
    <p>{l s='Chat live with your customers from this back office, the ChatPuff dashboard or your phone. Connecting takes a minute: sign in to ChatPuff, or create a free account, and confirm the shop.' d='Modules.Chatpuff.Admin'}</p>
    <p class="text-muted">{l s='Shop address: %domain%' sprintf=['%domain%' => $chatpuff.domain] d='Modules.Chatpuff.Admin'}</p>
    <form method="post" action="{$chatpuff.admin_url|escape:'html':'UTF-8'}" data-chatpuff-connect="{$chatpuff.start_url|escape:'html':'UTF-8'}">
      <button type="submit" name="chatpuffConnect" value="1" class="btn btn-primary">{l s='Connect to ChatPuff' d='Modules.Chatpuff.Admin'}</button>
    </form>
  {/if}
</div>
