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

/*
 * The badge next to ChatPuff and Inbox in the back-office menu: what waits for the employee in
 * ChatPuff (api-contract.md §9), fetched with a staff token from the Inbox tab, kept fresh every
 * minute while the page is visible, and remembered for this tab so that moving between pages
 * costs nothing. A chime when a chat starts waiting, unless the inbox itself is on the page,
 * following the inbox's own sound switch.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'chatpuff.badge';
  var ALERTS_KEY = 'chatpuff.inbox.alerts';
  var POLL_MS = 60000;
  var FRESH_MS = 20000;
  var QUIET_MS = 3600000;
  var SELECTORS = ['#subtab-AdminChatpuff > a', '#subtab-AdminChatpuffInbox > a'];

  function init() {
    var node = document.getElementById('chatpuff-badge-setup');
    if (!node || !window.fetch || !window.Promise) {
      return;
    }
    var setup;
    try {
      setup = JSON.parse(node.textContent || '');
    } catch (error) {
      return;
    }
    var onInbox = !!document.querySelector('[data-chatpuff-backoffice]');
    var baseTitle = document.title;
    var memory = read();
    var audio = null;
    var pending = null;

    function read() {
      try {
        return JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) || '{}') || {};
      } catch (error) {
        return {};
      }
    }

    function write() {
      try {
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(memory));
      } catch (error) {
        // No storage: this page only.
      }
    }

    function style() {
      if (document.getElementById('chatpuff-badge-style')) {
        return;
      }
      var css = document.createElement('style');
      css.id = 'chatpuff-badge-style';
      css.textContent = '.chatpuff-badge{display:inline-block;min-width:18px;margin-left:6px;padding:0 5px;border-radius:9px;background:#e94399;color:#fff;font-size:11px;font-weight:700;line-height:18px;text-align:center;vertical-align:middle}.chatpuff-badge[hidden]{display:none}';
      document.head.appendChild(css);
    }

    function paint(total) {
      style();
      SELECTORS.forEach(function (selector) {
        var link = document.querySelector(selector);
        if (!link) {
          return;
        }
        var badge = link.querySelector('.chatpuff-badge');
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'chatpuff-badge';
          link.appendChild(badge);
        }
        badge.textContent = String(total);
        badge.hidden = total === 0;
      });
      if (!onInbox) {
        document.title = (total > 0 ? '(' + total + ') ' : '') + baseTitle;
      }
    }

    function post(url) {
      return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) { return response.json(); });
    }

    // The staff token kept for this tab, or a new one from the module. An employee who is not
    // linked, or has no access, is not asked again for an hour.
    function token(renew) {
      if (!renew && memory.token && memory.renewAt > Date.now()) {
        return Promise.resolve(memory.token);
      }
      if (!pending) {
        pending = post(setup.token_url).then(function (data) {
          pending = null;
          if (data.state !== 'ready' || !data.access_token) {
            memory.token = null;
            memory.quietUntil = Date.now() + QUIET_MS;
            write();
            throw new Error(data.state || 'error');
          }
          var lifetime = Date.parse(String(data.expires_at).replace(/(\.\d{3})\d+/, '$1')) - Date.now();
          if (!(lifetime > 120000 && lifetime <= 900000)) {
            lifetime = 600000;
          }
          memory.token = data.access_token;
          memory.renewAt = Date.now() + lifetime - 60000;
          write();
          return memory.token;
        }, function (error) {
          pending = null;
          throw error;
        });
      }
      return pending;
    }

    function refresh(renewed) {
      if (memory.quietUntil > Date.now()) {
        return;
      }
      token(!!renewed).then(function (accessToken) {
        return fetch(setup.api + '/staff/v1/unread', {
          headers: { Accept: 'application/json', Authorization: 'Bearer ' + accessToken },
          credentials: 'omit'
        });
      }).then(function (response) {
        if (response.status === 401 && !renewed) {
          return refresh(true);
        }
        if (!response.ok) {
          return null;
        }
        return response.json().then(function (counts) {
          var waiting = counts.waiting || 0;
          if (!onInbox && typeof memory.waiting === 'number' && waiting > memory.waiting) {
            chime();
          }
          memory.waiting = waiting;
          memory.total = counts.total || 0;
          memory.at = Date.now();
          write();
          paint(memory.total);
        });
      }).catch(function () {});
    }

    function soundOn() {
      try {
        var saved = JSON.parse(window.localStorage.getItem(ALERTS_KEY) || '{}');
        return typeof saved.sound === 'boolean' ? saved.sound : true;
      } catch (error) {
        return true;
      }
    }

    // Two short sine notes, as in the inbox. Browsers allow sound after a click or a key on the
    // page; before that the badge alone tells.
    function chime() {
      var Context = window.AudioContext || window.webkitAudioContext;
      if (!soundOn() || !Context) {
        return;
      }
      try {
        audio = audio || new Context();
        if (audio.state !== 'running') {
          return;
        }
        var at = audio.currentTime;
        [[880, 0.18], [1174.66, 0.32]].forEach(function (note) {
          var oscillator = audio.createOscillator();
          var gain = audio.createGain();
          oscillator.type = 'sine';
          oscillator.frequency.value = note[0];
          gain.gain.setValueAtTime(0.0001, at);
          gain.gain.exponentialRampToValueAtTime(0.3, at + 0.02);
          gain.gain.exponentialRampToValueAtTime(0.0001, at + note[1]);
          oscillator.connect(gain);
          gain.connect(audio.destination);
          oscillator.start(at);
          oscillator.stop(at + note[1]);
          at += note[1];
        });
      } catch (error) {
        // No sound: the badge alone tells.
      }
    }

    function warmAudio() {
      var Context = window.AudioContext || window.webkitAudioContext;
      if (!Context || !soundOn()) {
        return;
      }
      try {
        audio = audio || new Context();
        if (audio.state === 'suspended') {
          audio.resume().catch(function () {});
        }
      } catch (error) {
        // No sound on this browser.
      }
    }

    document.addEventListener('pointerdown', warmAudio, { once: true, passive: true });
    document.addEventListener('keydown', warmAudio, { once: true, passive: true });

    if (typeof memory.total === 'number') {
      paint(memory.total);
    }
    if (!(typeof memory.at === 'number' && Date.now() - memory.at < FRESH_MS)) {
      refresh();
    }
    window.setInterval(function () {
      if (!document.hidden) {
        refresh();
      }
    }, POLL_MS);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !(typeof memory.at === 'number' && Date.now() - memory.at < FRESH_MS)) {
        refresh();
      }
    });
    // The inbox on this page asks for a fresh count when it changes something.
    window.ChatPuffInboxBadge = { refresh: function () { refresh(); } };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
