<?php
class Shopware_Controllers_Backend_BoxalinoExport extends Shopware_Controllers_Backend_ExtJs
{

    /**
     * index action is called if no other action is triggered
     *
     * @return void
     */
    public function indexAction()
    {
        $this->View()->loadTemplate('backend/boxalino_export/app.js');
        $this->View()->assign('title', 'Boxalino-Export');
    }

    public function fullAction() {
        $this->exportData();
    }

    public function deltaAction() {
        $this->exportData(true);
    }

    private function exportData($delta = false) {
        $tmpPath = Shopware()->DocPath('media_temp_boxalinoexport');
        $exporter = new Shopware_Plugins_Frontend_Boxalino_DataExporter($tmpPath, $delta);
        $this->View()->assign($exporter->run());
    }
}
