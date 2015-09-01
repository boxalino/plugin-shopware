<?php
require_once __DIR__ . '/lib/vendor/Thrift/ClassLoader/ThriftClassLoader.php';
require_once __DIR__ . '/lib/vendor/Thrift/HttpP13n.php';

class Shopware_Plugins_Frontend_Boxalino_Bootstrap
    extends Shopware_Components_Plugin_Bootstrap
{

    /**
     * @var Shopware_Plugins_Frontend_Boxalino_SearchInterceptor
     */
    private $searchInterceptor;
    /**
     * @var Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor
     */
    private $frontendInterceptor;

    public function __construct($name, $info = null)
    {
        parent::__construct($name, $info);

        if (version_compare(Shopware::VERSION, '5.0.0', '>=')) {
            $searchInterceptor = new Shopware_Plugins_Frontend_Boxalino_SearchInterceptor($this);
        } else {
            $searchInterceptor = new Shopware_Plugins_Frontend_Boxalino_SearchInterceptor4($this);
        }
        $this->searchInterceptor = $searchInterceptor;
        $this->frontendInterceptor = new Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor($this);
    }

    public function getCapabilities()
    {
        return array(
            'install' => true,
            'update' => true,
            'enable' => true
        );
    }

    public function getLabel()
    {
        return 'boxalino';
    }

    public function getVersion()
    {
        return '1.1.0';
    }

    public function getInfo()
    {
        return array(
            'version' => $this->getVersion(),
            'label' => $this->getLabel(),
            'author' => 'boxalino AG',
            'copyright' => 'Copyright © 2014, boxalino AG',
            'description' => 'Integrates boxalino search & recommendation into Shopware.',
            'support' => 'support@boxalino.com',
            'link' => 'http://www.boxalino.com/',
        );
    }

    /**
     * @return \Shopware\Components\Model\ModelManager
     */
    protected function getEntityManager()
    {
        return Shopware()->Models();
    }

    public function install()
    {
        try {
            $this->registerEvents();
            $this->createConfiguration();
            $this->applyBackendViewModifications();
            $this->createDatabase();
            $this->registerCronJobs();
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
        return true;
    }

    public function uninstall()
    {
        try {
            $this->removeDatabase();
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
        return array('success' => true, 'invalidateCache' => array('frontend'));
    }

    private function registerCronJobs()
    {
        $this->createCronJob(
            'BoxalinoExport',
            'BoxalinoExportCron',
            24 * 60 * 60,
            true
        );

        $this->subscribeEvent(
            'Shopware_CronJob_BoxalinoExportCron',
            'onBoxalinoExportCronJob'
        );

        $this->createCronJob(
            'BoxalinoExportDelta',
            'BoxalinoExportCronDelta',
            60 * 60,
            true
        );

        $this->subscribeEvent(
            'Shopware_CronJob_BoxalinoExportCronDelta',
            'onBoxalinoExportCronJobDelta'
        );
    }


    public function onBoxalinoExportCronJob(Shopware_Components_Cron_CronJob $job)
    {
        return $this->runBoxalinoExportCronJob();
    }

    public function onBoxalinoExportCronJobDelta(Shopware_Components_Cron_CronJob $job)
    {
        return $this->runBoxalinoExportCronJob(true);
    }

    private function runBoxalinoExportCronJob($delta = false)
    {
        $tmpPath = Shopware()->DocPath('media_temp_boxalinoexport');
        $exporter = new Shopware_Plugins_Frontend_Boxalino_DataExporter($tmpPath, $delta);
        $exporter->run();
        return true;
    }

    private function createDatabase()
    {
        $db = Shopware()->Db();
        $db->query(
            'CREATE TABLE IF NOT EXISTS ' . $db->quoteIdentifier('exports') .
            ' ( ' . $db->quoteIdentifier('export_date') . ' DATETIME)'
		);
    }

    private function removeDatabase()
    {
        $db = Shopware()->Db();
        $db->query(
            'DROP TABLE IF EXISTS ' . $db->quoteIdentifier('exports')
        );
    }

    private function registerEvents()
    {
        $this->subscribeEvent('Enlight_Controller_Action_Frontend_Search_DefaultSearch', 'onSearch');
        $this->subscribeEvent('Enlight_Controller_Action_Frontend_AjaxSearch_Index', 'onAjaxSearch');

        $this->subscribeEvent('Enlight_Controller_Action_PostDispatch_Frontend', 'onFrontend');
        $this->subscribeEvent('Enlight_Controller_Action_PostDispatch_Widgets', 'onWidget');

        $this->subscribeEvent('Enlight_Controller_Dispatcher_ControllerPath_Backend_BoxalinoExport', 'boxalinoBackendControllerExport');

        $this->subscribeEvent('Enlight_Controller_Action_PostDispatch_Backend_Customer', 'onBackendCustomerPostDispatch');
    }

    public function boxalinoBackendControllerExport()
    {
        Shopware()->Template()->addTemplateDir(Shopware()->Plugins()->Frontend()->Boxalino()->Path() . 'Views/');

        return Shopware()->Plugins()->Frontend()->Boxalino()->Path() . "/Controllers/backend/BoxalinoExport.php";
    }

    public function onSearch(Enlight_Event_EventArgs $arguments)
    {
        return $this->searchInterceptor->search($arguments);
    }

    public function onAjaxSearch(Enlight_Event_EventArgs $arguments)
    {
        return $this->searchInterceptor->ajaxSearch($arguments);
    }

    public function onFrontend(Enlight_Event_EventArgs $arguments) {
        $this->frontendInterceptor->intercept($arguments);
    }

    public function createConfiguration()
    {
        $scopeShop = Shopware\Models\Config\Element::SCOPE_SHOP;
        $scopeLocale = Shopware\Models\Config\Element::SCOPE_LOCALE;
        $storeNoYes = array(
            array(0, 'No'),
            array(1, 'Yes'),
        );
        $fields = array(array(
            'type' => 'select',
            'name' => 'dev',
            'label' => 'Development environment',
            'store' => $storeNoYes,
            'value' => 1,
        ), array(
            'name' => 'domain',
            'label' => 'Cookie Domain',
            'scope' => $scopeLocale
        ), array(
            'name' => 'account',
            'label' => 'Data Intelligence Account',
        ), array(
            'name' => 'form_username',
            'label' => 'Data Intelligence Username',
        ), array(
            'name' => 'form_password',
            'label' => 'Data Intelligence Password',
        ), array(
            'name' => 'host',
            'label' => 'P13n Host',
            'value' => 'cdn.bx-cloud.com',
        ), array(
            'name' => 'username',
            'label' => 'P13n Username',
        ), array(
            'name' => 'password',
            'label' => 'P13n Password',
        ), array(
            'name' => 'search_widget_name',
            'label' => 'Search Choice ID',
            'value' => 'search',
        ), array(
            'name' => 'autocomplete_widget_name',
            'label' => 'Autocomplete Choice ID',
            'value' => 'autocomplete',
        ), array(
            'name' => 'recommendation_widget_name',
            'label' => 'Recommendation Choice ID',
            'value' => 'recommendation',
        ), array(
            'type' => 'select',
            'name' => 'tracking_enabled',
            'label' => 'Tracking Enabled (default: Yes)',
            'store' => $storeNoYes,
            'value' => 1,
        ), array(
            'type' => 'select',
            'name' => 'search_enabled',
            'label' => 'Search & Autocompletion Enabled (default: Yes)',
            'store' => $storeNoYes,
            'value' => 1,
        ), array(
            'type' => 'select',
            'name' => 'product_recommendation_enabled',
            'label' => 'Recommendations Enabled (default: Yes)',
            'store' => $storeNoYes,
            'value' => 1,
        ), array(
            'type' => 'select',
            'name' => 'export',
            'label' => 'Data Export Enabled (default: Yes)',
            'store' => $storeNoYes,
            'value' => 1,
        ), array(
            'type' => 'select',
            'name' => 'export_delta',
            'label' => 'Hourly Delta Sync Enabled (default: No)',
            'store' => $storeNoYes,
            'value' => 0,
        ), array(
            'type' => 'select',
            'name' => 'customer_email',
            'label' => 'Export Customer Email Addresses (default: No)',
            'store' => $storeNoYes,
            'value' => 0,
        ), array(
            'type' => 'select',
            'name' => 'transaction_style',
            'label' => 'Transaction Export Style (default: Incremental)',
            'store' => array(
                array(1, 'Incremental'),
                array(0, 'Full Export')
            ),
            'value' => 1
        ));

        $form = $this->Form();
        foreach($fields as $f) {
            $type = 'text';
            $name = 'boxalino_' . $f['name'];
            if (array_key_exists('type', $f)) {
                $type = $f['type'];
                unset($f['type']);
            }
            unset($f['name']);
            if (!array_key_exists('value', $f)) {
                $f['value'] = '';
            }
            if (!array_key_exists('scope', $f)) {
                $f['scope'] = $scopeShop;
            }
            $form->setElement($type, $name, $f);
        }
    }

    /**
     * Called when the BackendCustomerPostDispatch Event is triggered
     *
     * @param Enlight_Event_EventArgs $args
     */
    public function onBackendCustomerPostDispatch(Enlight_Event_EventArgs $args)
    {

        /**@var $view Enlight_View_Default*/
        $view = $args->getSubject()->View();

        // Add template directory
        $args->getSubject()->View()->addTemplateDir($this->Path() . 'Views/');

        //if the controller action name equals "load" we have to load all application components
        if ($args->getRequest()->getActionName() === 'load') {
            $view->extendsTemplate('backend/customer/model/customer_preferences/attribute.js');
            $view->extendsTemplate('backend/customer/model/customer_preferences/list.js');
            $view->extendsTemplate('backend/customer/view/list/customer_preferences/list.js');
            $view->extendsTemplate('backend/customer/view/detail/customer_preferences/window.js');
            $view->extendsTemplate('backend/boxalino_export/view/main/window.js');

            //if the controller action name equals "index" we have to extend the backend customer application
            if ($args->getRequest()->getActionName() === 'index') {
                $view->extendsTemplate('backend/customer/customer_preferences_app.js');
                $view->extendsTemplate('backend/boxalino_export/boxalino_export_app.js');
            }
        }
    }

    private function applyBackendViewModifications()
    {
        try {
            $parent = $this->Menu()->findOneBy('label', 'import/export');
            $this->createMenuItem(array('label' => 'Boxalino Export', 'class' => 'sprite-cards-stack', 'active' => 1,
                'controller' => 'BoxalinoExport', 'action' => 'index', 'parent' => $parent));
        } catch (Exception $exception) {
            Shopware()->Log()->Err('can\'t create menu entry: ' . $exception->getMessage());
            throw new Exception('can\'t create menu entry: ' . $exception->getMessage());
        }
    }
}
