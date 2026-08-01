(function () {
    'use strict';

    var root = document.querySelector('[data-onesmtp-workspaces]');
    if (! root) {
        return;
    }

    var sections = Array.prototype.slice.call(root.querySelectorAll('[data-onesmtp-workspace]'));
    var links = Array.prototype.slice.call(root.querySelectorAll('[data-onesmtp-workspace-link]'));
    var aliases = {
        'onesmtp-general': 'onesmtp-overview',
        'onesmtp-dashboard': 'onesmtp-analytics',
        'onesmtp-setup': 'onesmtp-overview',
        'onesmtp-delivery': 'onesmtp-overview',
        'onesmtp-settings': 'onesmtp-settings',
        'onesmtp-logs': 'onesmtp-activity',
        'onesmtp-diagnostics': 'onesmtp-settings',
        'onesmtp-alerts': 'onesmtp-settings',
        'onesmtp-tools': 'onesmtp-settings'
    };

    function resolveWorkspace() {
        var queryTab = new URLSearchParams(window.location.search).get('tab');
        var target = window.location.hash.replace(/^#/, '');
        var resolved = queryTab || aliases[target] || target;
        var exists = sections.some(function (section) {
            return section.dataset.onesmtpWorkspace === resolved;
        });

        return exists ? resolved : 'onesmtp-overview';
    }

    // Fragment-only links from pre-0.3.0 URLs need a server-rendered screen.
    var legacyTarget = aliases[window.location.hash.replace(/^#/, '')];
    var hasTab = new URLSearchParams(window.location.search).has('tab');
    if (legacyTarget && ! hasTab && legacyTarget !== 'onesmtp-overview') {
        var legacyUrl = new URL(window.location.href);
        legacyUrl.searchParams.set('tab', legacyTarget);
        window.location.replace(legacyUrl.toString());
        return;
    }

    function activateWorkspace(workspaceId, moveFocus) {
        var activeSection = null;
        var activeLink = null;

        sections.forEach(function (section) {
            var isActive = section.dataset.onesmtpWorkspace === workspaceId;
            section.hidden = ! isActive;
            section.setAttribute('aria-hidden', isActive ? 'false' : 'true');

            if (isActive) {
                activeSection = section;
            }
        });

        links.forEach(function (link) {
            var isActive = link.dataset.onesmtpWorkspaceLink === workspaceId;
            link.classList.toggle('nav-tab-active', isActive);

            if (isActive) {
                link.setAttribute('aria-current', 'page');
                activeLink = link;
            } else {
                link.removeAttribute('aria-current');
            }
        });

        root.setAttribute('data-onesmtp-workspaces-ready', 'true');

        var navigation = root.querySelector('.onesmtp-admin-nav');
        if (navigation && activeLink && navigation.scrollWidth > navigation.clientWidth) {
            navigation.scrollLeft = Math.max(
                0,
                activeLink.offsetLeft - ((navigation.clientWidth - activeLink.offsetWidth) / 2)
            );
        }

        if (! moveFocus || ! activeSection) {
            return;
        }

        var targetId = window.location.hash.replace(/^#/, '');
        if (targetId && targetId !== workspaceId) {
            var target = document.getElementById(targetId);
            if (target) {
                target.scrollIntoView({ block: 'start' });
                return;
            }
        }

        var heading = activeSection.querySelector('h2[tabindex="-1"]');
        if (heading) {
            heading.focus();
        }
    }

    function openHashTarget() {
        var targetId = window.location.hash.replace(/^#/, '');
        if (! targetId) {
            return;
        }
        var target = document.getElementById(targetId);
        if (target && target.tagName.toLowerCase() === 'details') {
            target.open = true;
        }
    }

    links.forEach(function (link) {
        link.addEventListener('click', function (event) {
            var workspaceId = link.dataset.onesmtpWorkspaceLink;
            if (! workspaceId || ! sections.some(function (section) {
                return section.dataset.onesmtpWorkspace === workspaceId;
            })) {
                return;
            }

            event.preventDefault();
            var url = new URL(link.href, window.location.href);
            url.hash = workspaceId;
            url.searchParams.set('tab', workspaceId);
            window.history.pushState({ onesmtpWorkspace: workspaceId }, '', url.toString());
            activateWorkspace(workspaceId, true);
        });
    });

    root.querySelectorAll('[data-onesmtp-provider-type]').forEach(function (link) {
        link.addEventListener('click', function () {
            window.setTimeout(function () {
                var select = document.getElementById('onesmtp-provider-adapter_type');
                if (select) {
                    select.value = link.dataset.onesmtpProviderType || '';
                }
            }, 0);
        });
    });

    activateWorkspace(resolveWorkspace(), false);
    openHashTarget();

    window.addEventListener('hashchange', function () {
        activateWorkspace(resolveWorkspace(), true);
        openHashTarget();
    });

    window.addEventListener('popstate', function () {
        activateWorkspace(resolveWorkspace(), true);
    });
}());
