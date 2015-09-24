/**
 * window
 *
 * @category   Shopware
 * @package    Shopware_Plugins
 */

    //{namespace name=backend/order/main}
Ext.require([
    'Ext.grid.*', 'Ext.data.*', 'Ext.panel.*'
]);
Ext.define('Shopware.apps.BoxalinoExport.view.main.Window', {
    extend:    'Enlight.app.Window',
    title:     '{s namespace=Boxalino name=log_title}Boxalino Export{/s}',
    alias:     'widget.boxalino-export-main-window',
    border:    false,
    autoShow:  true,
    resizable: true,
    layout:    {
        type: 'fit'
    },
    height:    200,
    width:     300,

    initComponent:   function ()
    {
        var me = this;
        me.items = [
            me.createMainGrid(me)
        ];
        me.callParent(arguments);
    },
    createMainGrid:  function (me)
    {
        return Ext.create('Ext.panel.Panel', {
            id:          'mainGrid',
            forceFit:    true,
            border:      false,
            height:      '100%',
            width:       '100%',
            layout: {
                type: 'vbox',
                align: 'stretch',
                padding: 5
            },
            items: [
                {
                    xtype: 'button',
                    text: 'Full Export',
                    name: 'fex',
                    id: 'fex',
                    cls:'connectedSearch',
                    handler: function () {
                        var iAjax = new Ext.data.Connection();
                        iAjax.request({
                            url: ' {url module=backend controller=BoxalinoExport action=full}',
                            params : {
                                m : "Prodotti",
                                a : 'prod-price-byquantity',
                                idProd : 12,
                                quantity: 12
                            },
                            callback : function() {
                                window.open('{url module=backend controller=boxalino_export action=full}');
                            },
                            scope : this
                        });
                    }
                }, {
                    xtype: 'splitter'
                },
                {
                    xtype: 'button',
                    text: 'Delta Export',
                    name: 'dex',
                    id: 'dex',
                    cls:'connectedSearch',
                    handler: function () {
                        var iAjax = new Ext.data.Connection();
                        iAjax.request({
                            url: ' {url module=backend controller=boxalino_export action=delta}',
                            params : {
                                m : "Prodotti",
                                a : 'prod-price-byquantity',
                                idProd : 12,
                                quantity: 12
                            },
                            callback : function() {
                                window.open('{url module=backend controller=BoxalinoExport action=delta}');
                            },
                            scope : this
                        });
                    }
                }
            ]
        });
    }
});