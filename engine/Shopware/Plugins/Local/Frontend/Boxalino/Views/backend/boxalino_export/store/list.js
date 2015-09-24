/**
 * list
 *
 * @category   Shopware
 * @package    Shopware_Plugins
 */

Ext.define('Shopware.apps.BoxalinoExport.store.List', {
    extend:   'Ext.data.Store',
    model:    'Shopware.apps.BoxalinoExport.model.Main',
    autoLoad: true,
    pageSize: 32,
    proxy: {
        type: 'ajax',
        url : '{url action=loadStore}',
        reader: {
            type: 'json',
            root: 'data'
        }
    },
    remoteSort: true,
    remoteFilter: true

});