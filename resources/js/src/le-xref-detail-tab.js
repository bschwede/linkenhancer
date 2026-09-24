/**
 * Cross-reference detail tab injection for non-INDI record pages.
 *
 * Adds a "Cross-references" tab to the existing .nav-tabs on NOTE, MEDIA,
 * SOUR, REPO, and custom record pages. On FAM pages, adds a button that
 * opens a Bootstrap modal.
 *
 * Content is loaded lazily via AJAX when the tab/modal is first shown.
 * The badge count is updated after the AJAX response loads.
 */

const XREF_DETAIL_URL = '/le-xref-detail/';
const TAB_ID = 'le-xref-pane';
const TAB_HREF = '#le-xrefs';
const MODAL_ID = 'le-xref-modal';

function buildDetailUrl(tree, xref) {
    return `/${tree}${XREF_DETAIL_URL}${xref}`;
}

function injectTab(detailUrl, tabTitle) {
    const navTabs = document.querySelector('.nav.nav-tabs');
    const tabContent = document.querySelector('.tab-content');
    if (!navTabs || !tabContent) {
        return;
    }

    const li = document.createElement('li');
    li.className = 'nav-item';
    li.setAttribute('role', 'presentation');

    const a = document.createElement('a');
    a.className = 'nav-link';
    a.setAttribute('data-bs-toggle', 'tab');
    a.setAttribute('role', 'tab');
    a.setAttribute('href', TAB_HREF);
    a.setAttribute('data-wt-href', detailUrl);
    a.innerHTML = `${tabTitle} <span class="badge bg-secondary" id="le-xref-badge"></span>`;

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

function injectFamilyButton(detailUrl, buttonLabel) {
    const titleEl = document.querySelector('.wt-page-title');
    if (!titleEl) {
        return;
    }

    const btn = document.createElement('button');
    btn.className = 'btn btn-outline-primary btn-sm ms-3';
    btn.textContent = buttonLabel;
    btn.type = 'button';

    const modalHtml = `
    <div class="modal fade" id="${MODAL_ID}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">${buttonLabel}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="${MODAL_ID}-body"></div>
            </div>
        </div>
    </div>`;
    const modalWrapper = document.createElement('div');
    modalWrapper.innerHTML = modalHtml;
    document.body.appendChild(modalWrapper);

    btn.addEventListener('click', function () {
        const body = document.getElementById(`${MODAL_ID}-body`);
        if (body && !body.innerHTML.trim()) {
            webtrees.httpGet(detailUrl).then(data => data.text()).then(html => {
                body.innerHTML = html;
            });
        }
        const modalEl = document.getElementById(MODAL_ID);
        if (modalEl) {
            const Modal = bootstrap.Modal;
            new Modal(modalEl).show();
        }
    });

    titleEl.parentElement.appendChild(btn);
}

/**
 * Initialize the xref detail tab. Called from PHP on record detail pages.
 *
 * @param {object} config
 * @param {string} config.tree    - tree name (URL segment)
 * @param {string} config.xref    - record XREF
 * @param {string} config.rectype - record type (NOTE, MEDIA, SOUR, REPO, FAM, etc.)
 * @param {string} config.tabTitle - translated tab title
 */
export function initXrefDetailTab({ tree, xref, rectype, tabTitle }) {
    const detailUrl = buildDetailUrl(tree, xref);

    if (rectype === 'FAM') {
        injectFamilyButton(detailUrl, tabTitle);
    } else {
        injectTab(detailUrl, tabTitle);
    }
}
