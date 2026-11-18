(function (blocks, i18n, element, components, editor) {
    const { registerPaymentMethod } = wc.wcBlocksRegistry;
    // Use the localized data from PHP
    const chain2paygateways = chain2paygatewayData || [];
    chain2paygateways.forEach((chain2paygateway) => {
        registerPaymentMethod({
            name: chain2paygateway.id,
            label: chain2paygateway.label,
            ariaLabel: chain2paygateway.label,
            content: element.createElement(
                'div',
                { className: 'chain2paygateway-method-wrapper' },
                element.createElement(
                    'div',
                    { className: 'chain2paygateway-method-label' },
                    '' + chain2paygateway.description
                ),
                chain2paygateway.icon_url ? element.createElement(
                    'img',
                    {
                        src: chain2paygateway.icon_url,
                        alt: chain2paygateway.label,
                        className: 'chain2paygateway-method-icon'
                    }
                ) : null
            ),
            edit: element.createElement(
                'div',
                { className: 'chain2paygateway-method-wrapper' },
                element.createElement(
                    'div',
                    { className: 'chain2paygateway-method-label' },
                    '' + chain2paygateway.description
                ),
                chain2paygateway.icon_url ? element.createElement(
                    'img',
                    {
                        src: chain2paygateway.icon_url,
                        alt: chain2paygateway.label,
                        className: 'chain2paygateway-method-icon'
                    }
                ) : null
            ),
            canMakePayment: () => true,
        });
    });
})(
    window.wp.blocks,
    window.wp.i18n,
    window.wp.element,
    window.wp.components,
    window.wp.blockEditor
);