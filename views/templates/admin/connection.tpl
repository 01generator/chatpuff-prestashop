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
