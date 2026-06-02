const settings_zpay = window.wc.wcSettings.getSetting( 'zpay_gateway_data', {} ); //No I18N
const label_zpay = window.wp.htmlEntities.decodeEntities( settings_zpay.title ) || window.wp.i18n.__( 'Pay via Zoho Payments', 'zpay' ); //No I18N
const ContentZpay = () => {
return window.wp.htmlEntities.decodeEntities( settings_zpay.description || '' );
};
const Block_Gateway_Zpay = {
name: 'zpay', //No I18N
label: label_zpay,
content: Object( window.wp.element.createElement )( ContentZpay, null ),
edit: Object( window.wp.element.createElement )( ContentZpay, null ),
canMakePayment: () => true,
ariaLabel: label_zpay,
supports: {
features: settings_zpay.supports
 }
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway_Zpay );