/**
 * app
 *
 * @category   Shopware
 * @package    Shopware_Plugins
 */
Ext.define('Shopware.apps.BoxalinoExport', {
    extend:      'Enlight.app.SubApplication',
    name:        'Shopware.apps.BoxalinoExport',
    bulkLoad:    true,
    loadPath:    '{url action=load}',
    controllers: ['Main'],
//    models:      ['Main'],
    views:       ['main.Window'],
//    store:       ['List'],
    launch:      function ()
    {
        var me = this;
        me.windowTitle = '{s namespace=Boxalino name=log_title}Boxalino Export{/s}';
        var mainController = me.getController('Main');
        return mainController.mainWindow;
    }
});