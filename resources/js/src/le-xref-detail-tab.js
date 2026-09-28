/**
 * Cross-reference detail tab injection for non-INDI record pages.
 *
 * Uses a DOM observer (set up before body is parsed) to inject:
 *  - A tab into .nav.nav-tabs (NOTE, MEDIA, SOUR, REPO)
 *  - A link that opens the core #wt-ajax-modal (FAM)
 *
 * The observer fires during body parsing → injection happens before first paint.
 * Content is loaded lazily via AJAX on first tab show / modal show.
 */

import { createDomObserver } from './dom-observer-factory.js';

const TAB_ID = '_linkenhancer_';


function injectTab(navTabs, detailUrl, tabTitle) {
    const tabContent = document.querySelector('.tab-content');
    if (!tabContent) {
        return;
    }

    const li = document.createElement('li');
    li.className = 'nav-item';
    li.setAttribute('role', 'presentation');

    const a = document.createElement('a');
    a.className = 'nav-link';
    a.setAttribute('data-bs-toggle', 'tab');
    a.setAttribute('role', 'tab');
    a.setAttribute('href', `#${TAB_ID}`);
    a.setAttribute('data-wt-href', detailUrl);
    a.innerHTML = `${tabTitle} <span class="badge bg-secondary me-1" id="le-xref-badge"></span>`;

    a.addEventListener('show.bs.tab', function () {
        const target = document.getElementById(TAB_ID);
        if (target && !target.innerHTML.trim()) {
            webtrees.httpGet(detailUrl).then(data => data.text()).then(html => {
                target.innerHTML = html;
                const wrapper = target.querySelector('[data-xref-count]');
                if (wrapper) {
                    const badge = document.getElementById('le-xref-badge');
                    if (badge) {
                        badge.textContent = wrapper.getAttribute('data-xref-count');
                    }
                }
            });
        }
    });

    li.appendChild(a);
    navTabs.appendChild(li);

    const pane = document.createElement('div');
    pane.className = 'tab-pane fade';
    pane.setAttribute('role', 'tabpanel');
    pane.id = TAB_ID;
    tabContent.appendChild(pane);
}


function injectFamilyLink(titleEl, detailUrl, tabTitle) {
    const div = document.createElement('div'); // prevents stretching of button
    const a = document.createElement('button');
    a.href = '#';
    a.className = 'btn btn-primary ms-3 me-2 wt-page-menu-button';
    a.setAttribute('type', 'button');
    a.setAttribute('data-bs-toggle', 'modal');
    a.setAttribute('data-bs-target', '#wt-ajax-modal');
    a.setAttribute('data-wt-href', detailUrl);
    a.textContent = tabTitle;

    div.appendChild(a);
    titleEl.parentElement.appendChild(div);
}


/**
 * Initialize the xref detail tab. Called from PHP on record detail pages.
 * Sets up a DOM observer that injects the tab/button during body parsing.
 *
 * @param {object} config
 * @param {string} config.url     - detail URL (e.g. /tree-name/le-xref-detail/I0001)
 * @param {string} config.rectype - record type (NOTE, MEDIA, SOUR, REPO, FAM)
 * @param {string} config.tabTitle - translated tab title
 */
export function initXrefDetailTab({ url, rectype, tabTitle }) {
    if (rectype === 'FAM') {
        let obs;
        obs = createDomObserver({
            root: document.documentElement,
            match: node => node.classList?.contains('wt-page-title'),
            process: titleEl => {
                obs.disconnect();
                injectFamilyLink(titleEl, url, tabTitle);
            }
        });
    } else {
        let obs;
        obs = createDomObserver({
            root: document.documentElement,
            match: node => node.classList?.contains('nav') && node.classList?.contains('nav-tabs'),
            process: navTabs => {
                obs.disconnect();
                injectTab(navTabs, url, tabTitle);
            }
        });
    }
}
