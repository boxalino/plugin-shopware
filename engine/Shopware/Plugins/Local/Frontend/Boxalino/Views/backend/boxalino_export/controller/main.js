/**
 * main
 *
 * @category   Shopware
 * @package    Shopware_Plugins
 */

Ext.define('Shopware.apps.BoxalinoExport.controller.Main', {
    extend:     'Ext.app.Controller',
    mainWindow: null,
    connectedSearch: false,
    init:       function ()
    {
        var me = this;
        me.mainWindow = null;
        me.mainWindow = me.getView('main.Window').create({
//            listStore: me.getStore('List').load()
        });

        me.control({
            'boxalino_export-main-window [name=searchfield]': {
                change: me.onSearchForm
            },
            'boxalino_export-main-window [name=connectedSearch]': {
                change: me.onConnectedSearch
            }
        });

        me.callParent(arguments);
    },

    /**
     * Callback function triggered when the user enters something into the search field
     *
     * @param field
     * @param value
     */
    onSearchForm: function (field, value)
    {
        var me = this;
    },

    /**
     * Callback function triggered when the connected search state is beeing changed
     *
     * @param field
     * @param value
     */
    onConnectedSearch: function (field, value)
    {
        var me = this;
        me.connectedSearch = value;
    }
});