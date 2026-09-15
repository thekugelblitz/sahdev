/**
 * Sahdev AI Live Chat - Universal Embed Widget Loader (embed.js)
 * 
 * Automatically loads the live, fully synchronized chat widget matching your WHMCS configuration:
 *  - Exact Brand Color, Theme, and Custom Sizing
 *  - Custom Organization Title & Logo / Siri Orb
 *  - Launcher Style (Pill / Circle) & Text
 *  - WhatsApp Multi-Department Hub & Knowledge Base Search
 *  - Live Human Agent Takeover & Typing Sneak-Peek
 *  - Audio Chimes, CSAT Ratings & Markdown Formatter
 */
(function() {
    'use strict';

    if (window._sdvEmbedLoaded) return;

    // 1. Discover WHMCS Base URL from script tag or current location
    var scriptTag = document.currentScript || (function() {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            var s = scripts[i];
            var src = s.getAttribute('src') || s.src || '';
            if (src && (src.indexOf('embed_js') !== -1 || src.indexOf('embed.js') !== -1 || src.indexOf('sahdev') !== -1 || s.hasAttribute('data-whmcs-url'))) {
                return s;
            }
        }
        return null;
    })();

    var whmcsBaseUrl = '';
    if (scriptTag) {
        whmcsBaseUrl = scriptTag.getAttribute('data-whmcs-url') || '';
        if (!whmcsBaseUrl) {
            var scriptSrc = scriptTag.getAttribute('src') || scriptTag.src || '';
            if (scriptSrc) {
                try {
                    var scriptUrl = new URL(scriptSrc, window.location.href);
                    whmcsBaseUrl = scriptUrl.origin + scriptUrl.pathname
                        .replace(/\/modules\/addons\/sahdev\/embed\.js.*$/i, '')
                        .replace(/\/index\.php.*$/i, '');
                } catch (e) {
                    whmcsBaseUrl = '';
                }
            }
        }
    }
    if (!whmcsBaseUrl) {
        whmcsBaseUrl = window.location.origin;
    }
    whmcsBaseUrl = whmcsBaseUrl.replace(/\/+$/, '');

    // 2. Dynamically inject the live WHMCS-synchronized widget script
    var dynScript = document.createElement('script');
    dynScript.src = whmcsBaseUrl + '/index.php?m=sahdev&action=embed_js';
    dynScript.async = true;
    dynScript.defer = true;
    dynScript.setAttribute('data-sdv-loader', '1');

    var target = document.head || document.getElementsByTagName('head')[0] || document.documentElement;
    if (target) {
        target.appendChild(dynScript);
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            (document.head || document.documentElement).appendChild(dynScript);
        });
    }
})();
