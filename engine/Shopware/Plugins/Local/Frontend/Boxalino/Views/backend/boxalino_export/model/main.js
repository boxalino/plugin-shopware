Ext.define('Shopware.apps.BoxalinoExport.model.Main', {
    extend: 'Ext.data.Model',
    fields: [
        { name: 'id', type: 'int'},
        { name: 'processId', type: 'string'},
        { name: 'entryDate', type: 'string'},
        { name: 'version', type: 'string'},
        { name: 'merchantInfo', type: 'string'},
        { name: 'devInfo', type: 'string'}
    ]
});