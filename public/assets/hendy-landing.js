(() => {
  'use strict';
  const byId = (id) => document.getElementById(id);

  function safeTelegramUrl(value) {
    if (typeof value !== 'string' || value.length > 512) return '';
    try {
      const url = new URL(value);
      const hosts = new Set(['t.me', 'telegram.me', 'www.telegram.me']);
      if (url.protocol !== 'https:' || !hosts.has(url.hostname.toLowerCase()) || url.username || url.password || url.port) return '';
      if (!/^\/[A-Za-z0-9_+./-]{1,200}$/.test(url.pathname) || url.pathname.includes('..')) return '';
      return 'https://t.me' + url.pathname;
    } catch (_) {
      return '';
    }
  }

  function activateOptionalLink(id, candidate) {
    const element = byId(id);
    if (!element) return;
    const url = safeTelegramUrl(candidate);
    if (!url) {
      element.hidden = true;
      element.removeAttribute('href');
      return;
    }
    element.href = url;
    element.target = '_blank';
    element.rel = 'noopener noreferrer';
    element.hidden = false;
  }

  async function loadPublicLinks() {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 5000);
    try {
      const response = await fetch('/api/v1/public/config', {
        method: 'GET',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        credentials: 'same-origin',
        signal: controller.signal
      });
      if (!response.ok) throw new Error('Không thể tải các liên kết công khai.');
      const config = await response.json();
      const botUrl = safeTelegramUrl(config.botUrl);
      ['header-bot-link','hero-bot-link','footer-bot-link'].forEach((id) => {
        const link = byId(id);
        if (!link) return;
        if (botUrl) {
          link.href = botUrl;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
        } else {
          link.href = '/customer';
          link.removeAttribute('target');
          link.removeAttribute('rel');
        }
      });
      activateOptionalLink('group-link', config.groupUrl);
      activateOptionalLink('channel-link', config.channelUrl);
      activateOptionalLink('support-link', config.supportUrl);
    } catch (_) {
      // The static homepage navigation remains usable if optional URLs are unavailable.
    } finally {
      window.clearTimeout(timeout);
    }
  }

  const year = byId('current-year');
  if (year) year.textContent = String(new Date().getFullYear());
  loadPublicLinks();
})();
