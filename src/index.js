import { createRoot, useState } from '@wordpress/element';
import { Card, CardBody } from '@wordpress/components';
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
