<?php

namespace KareliaLabs\Conversions\Tags;

use KareliaLabs\Conversions\Attribution;
use KareliaLabs\Conversions\Settings;
use Statamic\Tags\Tags;

/**
 * {{ conversions:script }} — put it in your layout's <head>.
 *
 * Keeps UTM tags and ad click IDs from the landing page in a first-party
 * cookie (only after consent) so that a form sent later still knows where the
 * visitor came from. It works on statically cached pages too.
 *
 * Your cookie banner calls KareliaConversions.consent(true) when the visitor
 * accepts marketing cookies, and KareliaConversions.consent(false) when they
 * withdraw it.
 */
class ConversionsTag extends Tags
{
    protected static $handle = 'conversions';

    public function script(): string
    {
        $ownCookie = Settings::consentCookie() === config('statamic.conversions.consent.cookie', 'karelia_conversions_consent');

        $config = json_encode([
            'always' => Settings::consentMode() === 'always',
            'consentCookie' => Settings::consentCookie(),
            'consentValue' => Settings::consentValue(),
            'ownCookie' => $ownCookie,
            'cookie' => Settings::attributionCookie(),
            'days' => Settings::attributionDays(),
            'keys' => Attribution::KEYS,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

        $nonce = $this->params->get('nonce');
        $nonceAttr = $nonce ? ' nonce="'.e($nonce).'"' : '';

        return <<<HTML
<script{$nonceAttr}>
(function (w, d, c) {
    function get(n) {
        var parts = d.cookie ? d.cookie.split('; ') : [];
        for (var i = 0; i < parts.length; i++) {
            var at = parts[i].indexOf('=');
            if (parts[i].slice(0, at) === n) return decodeURIComponent(parts[i].slice(at + 1));
        }
        return null;
    }
    function set(n, v, age) {
        d.cookie = n + '=' + encodeURIComponent(v) + ';path=/;max-age=' + age + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
    }
    function consented() {
        if (c.always) return true;
        var v = get(c.consentCookie);
        return v !== null && (c.consentValue === '' ? v !== '' : v.indexOf(c.consentValue) !== -1);
    }
    var params = new URLSearchParams(location.search), found = {}, any = false;
    for (var i = 0; i < c.keys.length; i++) {
        var v = params.get(c.keys[i]);
        if (v) { found[c.keys[i]] = v.slice(0, 500); any = true; }
    }
    if (any) {
        found.ts = Date.now();
        found.landing = location.pathname.slice(0, 300);
        if (d.referrer && d.referrer.indexOf(location.host) === -1) found.referrer = d.referrer.slice(0, 300);
    }
    function store() {
        if (any && consented()) set(c.cookie, JSON.stringify(found), c.days * 86400);
    }
    store();
    w.KareliaConversions = {
        consent: function (on) {
            if (on) {
                if (c.ownCookie) set(c.consentCookie, c.consentValue || '1', 31536000);
                store();
            } else {
                if (c.ownCookie) set(c.consentCookie, '', 0);
                set(c.cookie, '', 0);
            }
        },
        hasConsent: consented
    };
})(window, document, {$config});
</script>
HTML;
    }
}
