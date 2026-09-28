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
(function () {
  'use strict';

  function init() {
    // Connect: open ChatPuff in a new tab right away. The tab is opened during the click so that
    // pop-up blockers allow it; without JavaScript the form posts and the page shows a link instead.
    document.querySelectorAll('form[data-chatpuff-connect]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.fetch) {
          return;
        }
        event.preventDefault();
        var tab = window.open('', '_blank');
        fetch(form.getAttribute('data-chatpuff-connect'), { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (tab && data.confirmation_url) {
              tab.opener = null;
              tab.location.href = data.confirmation_url;
            } else if (tab) {
              tab.close();
            }
            window.location.reload();
          })
          .catch(function () {
            if (tab) {
              tab.close();
            }
            form.submit();
          });
      });
    });

    // While the owner confirms in ChatPuff, check every three seconds and reload once it is decided.
    var poll = document.querySelector('[data-chatpuff-poll]');
    if (poll && window.fetch) {
      var timer = window.setInterval(function () {
        fetch(poll.getAttribute('data-chatpuff-poll'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (data.state && data.state !== 'pending') {
              window.clearInterval(timer);
              window.location.reload();
            }
          })
          .catch(function () {});
      }, 3000);
    }

    document.querySelectorAll('form[data-chatpuff-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-chatpuff-confirm'))) {
          event.preventDefault();
        }
      });
    });
  }

  // The back office loads module scripts in the page head, before the forms exist.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
