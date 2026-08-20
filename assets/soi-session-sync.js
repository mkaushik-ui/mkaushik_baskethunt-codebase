/**
 * SOI Accounts live session sync — polls Accounts + CMS session status,
 * coordinates logout across tabs via BroadcastChannel and localStorage.
 */
(function (window, document) {
    'use strict';

    var config = window.SOI_SESSION_SYNC || {};
    if (!config.enabled) {
        return;
    }

    var CHANNEL = 'soi_accounts_session_v1';
    var STORAGE_KEY = 'soi_accounts_session_revoked';
    var INFLIGHT_KEY = 'soi_accounts_logout_inflight';
    var pollMs = Math.max(15000, Math.min(300000, parseInt(config.pollIntervalMs, 10) || 15000));
    var accountsMeUrl = config.accountsMeUrl || '';
    var accountsEventsUrl = config.accountsEventsUrl || '';
    var cmsLiveCheckUrl = config.cmsLiveCheckUrl || config.cmsStatusUrl || '';
    var cmsLogoutUrl = config.cmsLogoutUrl || '';
    var checking = false;
    var loggedOut = false;
    var pollTimer = null;
    var debounceTimer = null;
    var eventSource = null;
    var sseReconnectTimer = null;
    var sseRetryCount = 0;

    function canUseStorage() {
        try {
            return !!window.localStorage;
        } catch (e) {
            return false;
        }
    }

    function markInflight() {
        if (!canUseStorage()) {
            return;
        }
        try {
            localStorage.setItem(INFLIGHT_KEY, String(Date.now()));
        } catch (e) {}
    }

    function isInflight() {
        if (!canUseStorage()) {
            return false;
        }
        try {
            var ts = parseInt(localStorage.getItem(INFLIGHT_KEY) || '0', 10);
            return ts > 0 && (Date.now() - ts) < 60000;
        } catch (e) {
            return false;
        }
    }

    function broadcastLogout(reason) {
        try {
            var bc = new BroadcastChannel(CHANNEL);
            bc.postMessage({ type: 'logout', reason: reason || 'unknown', ts: Date.now() });
            bc.close();
        } catch (e) {}
        if (canUseStorage()) {
            try {
                localStorage.setItem(STORAGE_KEY, String(Date.now()));
            } catch (e) {}
        }
    }

    function performLogout(reason) {
        if (loggedOut || isInflight()) {
            return;
        }
        loggedOut = true;
        markInflight();
        broadcastLogout(reason);
        closeEventSource();
        
        // Strict blocking: immediately hide the content while redirecting
        if (document.body) {
            document.body.style.display = 'none';
            document.documentElement.style.backgroundColor = '#f4f4f5';
        }
        
        var target = cmsLogoutUrl;
        var sep = target.indexOf('?') >= 0 ? '&' : '?';
        window.location.replace(target + sep + 'sso_sync=1&reason=' + encodeURIComponent(reason || 'session_sync'));
    }

    function parseJsonResponse(response) {
        if (!response || !response.ok) {
            return null;
        }
        return response.json().catch(function () {
            return null;
        });
    }

    function checkAccountsSession() {
        if (!accountsMeUrl) {
            return Promise.resolve(null);
        }
        return fetch(accountsMeUrl, { credentials: 'include', cache: 'no-store' })
            .then(parseJsonResponse)
            .catch(function () {
                return null;
            });
    }

    function checkCmsSession() {
        if (!cmsLiveCheckUrl) {
            return Promise.resolve(null);
        }
        return fetch(cmsLiveCheckUrl, { credentials: 'include', cache: 'no-store' })
            .then(parseJsonResponse)
            .catch(function () {
                return null;
            });
    }

    function runCheck() {
        if (loggedOut || checking) {
            return Promise.resolve();
        }
        checking = true;

        return Promise.all([checkAccountsSession(), checkCmsSession()])
            .then(function (results) {
                var accountsData = results[0];
                var cmsData = results[1];

                if (accountsData && accountsData.authenticated === false) {
                    performLogout('accounts_embed_me');
                    return;
                }

                if (cmsData) {
                    if (cmsData.should_logout === true || cmsData.authenticated === false) {
                        performLogout(cmsData.reason || 'cms_session_status');
                        return;
                    }
                }
            })
            .finally(function () {
                checking = false;
            });
    }

    function debouncedCheck() {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(function () {
            debounceTimer = null;
            runCheck();
        }, 400);
    }

    function startPoll() {
        stopPoll();
        if (document.visibilityState !== 'visible' || loggedOut) {
            return;
        }
        pollTimer = setInterval(runCheck, pollMs);
    }

    function stopPoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function connectEventSource() {
        if (loggedOut || !window.EventSource || !accountsEventsUrl) {
            return;
        }
        if (eventSource && (eventSource.readyState === EventSource.OPEN || eventSource.readyState === EventSource.CONNECTING)) {
            return;
        }
        
        try {
            eventSource = new EventSource(accountsEventsUrl, { withCredentials: true });
            
            eventSource.onopen = function() {
                sseRetryCount = 0;
            };

            eventSource.addEventListener('session_invalid', function(e) {
                var data = {};
                try {
                    data = JSON.parse(e.data);
                } catch(err) {}
                performLogout(data.reason || 'sse_session_invalid');
            });

            eventSource.addEventListener('heartbeat', function(e) {
                // Connection is alive
            });

            eventSource.onerror = function() {
                closeEventSource();
                if (loggedOut) return;
                sseRetryCount++;
                var delay = Math.min(1000 * Math.pow(2, sseRetryCount), 30000); // Exp backoff max 30s
                sseReconnectTimer = setTimeout(connectEventSource, delay);
            };
        } catch (e) {}
    }

    function closeEventSource() {
        if (eventSource) {
            try {
                eventSource.close();
            } catch(e) {}
            eventSource = null;
        }
        if (sseReconnectTimer) {
            clearTimeout(sseReconnectTimer);
            sseReconnectTimer = null;
        }
    }

    try {
        var channel = new BroadcastChannel(CHANNEL);
        channel.onmessage = function (event) {
            if (event && event.data && event.data.type === 'logout') {
                performLogout('broadcast');
            }
        };
    } catch (e) {}

    window.addEventListener('storage', function (event) {
        if (event && event.key === STORAGE_KEY && event.newValue) {
            performLogout('storage');
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            debouncedCheck();
            startPoll();
            connectEventSource();
        } else {
            stopPoll();
            // We can optionally leave EventSource open when hidden to ensure immediate logout upon waking,
            // but standard practice is to let it be, or close it to save battery. We leave it open.
        }
    });

    window.addEventListener('focus', function() {
        debouncedCheck();
        if (!eventSource || eventSource.readyState === EventSource.CLOSED) {
            connectEventSource();
        }
    });
    
    window.addEventListener('pageshow', function (event) {
        if (event && event.persisted) {
            debouncedCheck();
            connectEventSource();
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }
        var link = target.closest('a[href*="logout.php"], a[href*="/logout?"]');
        if (!link) {
            return;
        }
        broadcastLogout('user_click');
    }, true);

    if (window._soi_sso_sync) {
        return;
    }
    window._soi_sso_sync = true;

    runCheck();
    startPoll();
    connectEventSource();
})(window, document);