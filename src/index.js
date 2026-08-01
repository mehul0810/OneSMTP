import { createRoot, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, Modal, Notice, Spinner, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { DataViews } from '@wordpress/dataviews/wp';
import '@wordpress/dataviews/build-style/style.css';

const mount = document.querySelector('[data-onesmtp-component="analytics-summary"]');

if (mount) {
    createRoot(mount).render(
        <Card className="onesmtp-component-card">
            <CardBody>
                <strong>{__('Analytics is ready for provider-level comparisons.', 'onesmtp')}</strong>
                <p>{__('Cost and reliability signals will appear when provider pricing inputs are configured.', 'onesmtp')}</p>
            </CardBody>
        </Card>
    );
}

const senderIdentityMount = document.querySelector('[data-onesmtp-component="sender-identity-drawer"]');

const SenderIdentityDrawer = ({ config }) => {
    const initialIdentity = config.identity || {};
    const [isOpen, setIsOpen] = useState(false);
    const [fromEmail, setFromEmail] = useState(initialIdentity.from_email || '');
    const [fromName, setFromName] = useState(initialIdentity.from_name || '');
    const [isSaving, setIsSaving] = useState(false);
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        const triggers = document.querySelectorAll('[data-onesmtp-drawer-trigger="sender-identity"]');
        const fallback = document.getElementById('onesmtp-sender-identity-fallback');
        const openDrawer = (event) => {
            event.preventDefault();
            setNotice(null);
            setIsOpen(true);
        };

        if (fallback) {
            fallback.hidden = true;
        }
        triggers.forEach((trigger) => trigger.addEventListener('click', openDrawer));

        return () => {
            triggers.forEach((trigger) => trigger.removeEventListener('click', openDrawer));
            if (fallback) {
                fallback.hidden = false;
            }
        };
    }, []);

    const save = async () => {
        setIsSaving(true);
        setNotice(null);

        try {
            await apiFetch({
                url: config.endpoint,
                method: 'POST',
                headers: { 'X-WP-Nonce': config.nonce },
                data: { from_email: fromEmail, from_name: fromName },
            });
            setNotice({ status: 'success', message: __('Sender identity saved. Refreshing setup status…', 'onesmtp') });
            window.setTimeout(() => window.location.reload(), 600);
        } catch (error) {
            setNotice({
                status: 'error',
                message: error?.message || __('Unable to save sender identity. Check the fields and try again.', 'onesmtp'),
            });
            setIsSaving(false);
        }
    };

    if (!isOpen) {
        return null;
    }

    return (
        <Modal
            className="onesmtp-drawer"
            title={__('Sender identity', 'onesmtp')}
            onRequestClose={() => !isSaving && setIsOpen(false)}
        >
            <p className="onesmtp-drawer-description">
                {__('Choose the name and email address OneSMTP should use when sending WordPress email.', 'onesmtp')}
            </p>
            {notice && <Notice status={notice.status} isDismissible={false}>{notice.message}</Notice>}
            <TextControl
                label={__('From email', 'onesmtp')}
                type="email"
                value={fromEmail}
                onChange={setFromEmail}
                required
                help={__('Use an address authorised by your provider and sender domain.', 'onesmtp')}
            />
            <TextControl
                label={__('From name', 'onesmtp')}
                value={fromName}
                onChange={setFromName}
                required
            />
            <div className="onesmtp-drawer-actions">
                <Button variant="secondary" onClick={() => setIsOpen(false)} disabled={isSaving}>
                    {__('Cancel', 'onesmtp')}
                </Button>
                <Button variant="primary" onClick={save} disabled={isSaving || !fromEmail || !fromName}>
                    {isSaving ? <><Spinner /> {__('Saving…', 'onesmtp')}</> : __('Save sender identity', 'onesmtp')}
                </Button>
            </div>
        </Modal>
    );
};

if (senderIdentityMount) {
    let config = {};

    try {
        config = JSON.parse(senderIdentityMount.dataset.onesmtpSenderIdentityConfig || '{}');
    } catch (error) {
        senderIdentityMount.textContent = __('The sender identity form could not be loaded.', 'onesmtp');
    }

    createRoot(senderIdentityMount).render(<SenderIdentityDrawer config={config} />);
}

const renderDataViews = (mountNode, config, title) => {
    const fields = config.fields.map((field) => ({
        ...field,
        enableSorting: field.enableSorting !== false,
    }));

    const DataViewsList = () => {
        const [view, setView] = useState({
            type: 'table',
            perPage: 20,
            page: 1,
            search: '',
            filters: [],
            fields: fields.map((field) => field.id),
            layout: {},
        });

        return (
            <div className="onesmtp-dataviews-shell" aria-label={title}>
                <DataViews
                    data={config.data}
                    fields={fields}
                    view={view}
                    onChangeView={setView}
                    defaultLayouts={{ table: {} }}
                />
            </div>
        );
    };

    createRoot(mountNode).render(<DataViewsList />);
};

document.querySelectorAll('[data-onesmtp-dataviews]').forEach((mountNode) => {
    const key = mountNode.dataset.onesmtpDataviews;
    const configNode = document.querySelector(`[data-onesmtp-dataviews-config="${key}"]`);
    if (!configNode) {
        return;
    }

    try {
        renderDataViews(mountNode, JSON.parse(configNode.textContent), key === 'delivery-messages' ? __('OneSMTP delivery messages', 'onesmtp') : __('OneSMTP providers', 'onesmtp'));
    } catch (error) {
        mountNode.innerHTML = `<p class="notice notice-error">${__('The listing could not be loaded.', 'onesmtp')}</p>`;
    }
});
