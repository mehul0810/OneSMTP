(function () {
    'use strict';

    var root = document.querySelector('[data-onesmtp-workspaces]');
    if (! root) {
        return;
    }

    var sections = Array.prototype.slice.call(root.querySelectorAll('[data-onesmtp-workspace]'));
    var links = Array.prototype.slice.call(root.querySelectorAll('[data-onesmtp-workspace-link]'));
    var aliases = {
        'onesmtp-dashboard': 'onesmtp-general',
        'onesmtp-setup': 'onesmtp-general',
        'onesmtp-settings': 'onesmtp-routing',
        'onesmtp-diagnostics': 'onesmtp-tools',
        'onesmtp-alerts': 'onesmtp-tools'
    };

    function resolveWorkspace() {
        var target = window.location.hash.replace(/^#/, '');
        var resolved = aliases[target] || target;
        var exists = sections.some(function (section) {
            return section.dataset.onesmtpWorkspace === resolved;
        });

        return exists ? resolved : 'onesmtp-general';
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

    activateWorkspace(resolveWorkspace(), false);

    window.addEventListener('hashchange', function () {
        activateWorkspace(resolveWorkspace(), true);
    });
}());
