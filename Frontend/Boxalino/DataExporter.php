<?php

class Shopware_Plugins_Frontend_Boxalino_DataExporter
{
    protected $request;
    protected $manager;

    const CATEGORIES = 'categories';
    const CATEGORIES_CSV = 'categories.csv';
    const ITEM_BRANDS = 'item_brands';
    const ITEM_BRANDS_CSV = 'item_brands.csv';
    const ITEM_CATEGORIES = 'item_categories';
    const ITEM_CATEGORIES_CSV = 'item_categories.csv';
    const ITEM_PROPERTIES = 'item_vals';
    const ITEM_PROPERTIES_CSV = 'item_properties.csv';
    const ITEM_TRANSLATIONS = 'item_translations';
    const ITEM_TRANSLATIONS_CSV = 'item_translations.csv';
	const ITEM_FACETVALUES = 'item_facet_values';
    const ITEM_FACETVALUES_CSV = 'item_facet_values.csv';
	const ITEM_BLOGS = 'item_blogs';
	const ITEM_BLOGS_CSV = 'item_blogs.csv';
    const CUSTOMERS = 'customer_vals';
    const CUSTOMERS_CSV = 'customers.csv';
    const TRANSACTIONS = 'transactions';
    const TRANSACTIONS_CSV = 'transactions.csv';
	const STORE_INFO_TXT = 'store_info.txt';

    const URL_XML = 'http://di1.bx-cloud.com/frontend/dbmind/en/dbmind/api/data/source/update';
    const URL_XML_DEV = 'http://di1.bx-cloud.com/frontend/dbmind/en/dbmind/api/data/source/update?dev=true';

    const URL_ZIP = 'http://di1.bx-cloud.com/frontend/dbmind/en/dbmind/api/data/push';
    const URL_ZIP_DEV = 'http://di1.bx-cloud.com/frontend/dbmind/en/dbmind/api/data/push?dev=true';

    const XML_DELIMITER = ',';
    const XML_ENCLOSURE = '"';
    const XML_NEWLINE = '\\n';
    const XML_ESCAPE = '\\\\';
    const XML_ENCODE = 'UTF-8';
    const XML_FORMAT = 'CSV';

    /**
     * @var SimpleXMLElement
     */
    protected $xml;

    protected $propertyDescriptions = array();

    protected $dirPath;
    protected $db;
    protected $log;
    protected $delta;
    protected $deltaLast;
    protected $fileHandle;

    protected $_attributes = array();
    protected $config = array();
    protected $locales = array();
    protected $languages = array();
    protected $rootCategories = array();

    protected $translationFields = array(
        'txtArtikel'          => 'name',
        'txtshortdescription' => 'description',
        'txtlangbeschreibung' => 'description_long',
        'txtzusatztxt'        => 'additionaltext',
    );

    /**
     * constructor
     *
     * @param string $dirPath
     * @param bool   $delta
     */
    public function __construct($dirPath, $delta = false)
    {
        $this->delta = $delta;
        $this->dirPath = $dirPath;
        $this->db = Shopware()->Db();
        $this->log = Shopware()->PluginLogger();
    }

    /**
     * run the exporter
     *
     * iterates over all shops and exports them according to their settings
     *
     * @return array
     */
    public function run()
    {
        $type = $this->delta ? 'delta' : 'full';
        $this->log->info("Start of boxalino $type data sync");
        $data = array();
        foreach ($this->getMainShopIds() as $id) {
            // if data sync is enabled, run it for that shop id
            if ($this->getConfigurationByShopId($id, 'enabled')) {
                $resultPushXml = 'not pushed';
                $resultPushZip = 'not pushed';

                $status = $this->createExportFiles($id);
                if ($status) {
                    $resultPushXml = $this->pushXml($id);
                    $resultPushZip = $this->pushZip($id);
                }

                $data[$this->getConfigurationByShopId($id, 'account')] = array(
                    'xml' => $resultPushXml,
                    'zip' => $resultPushZip,
                    'success' => $status
                );
            }
        }
        $this->log->info("End of boxalino $type data sync, result: " . json_encode($data));
        return $data;
    }

    /**
     * create export files for given shop id
     *
     * @param int $id
     * @return boolean
     */
    protected function createExportFiles($id)
    {
        if (!is_dir($this->dirPath) && !@mkdir($this->dirPath, 0777, true)) {
            $this->log->error("Unable to create $this->dirPath for boxalino data sync");
            return false;
        }

        $metadataFactoryClassName = $this->getClassMetadataFactoryName();
        /** @var $metaDataFactory \Doctrine\ORM\Mapping\ClassMetadataFactory */
        $metaDataFactory = new $metadataFactoryClassName;
        $metaDataFactory->setEntityManager($this->getEntityManager());

        $zip_name = $this->dirPath . 'export.zip';
        @unlink($zip_name);

        $zip = new ZipArchive();
        if ($zip->open($zip_name, ZIPARCHIVE::CREATE) !== TRUE) {
            $this->log->error("Unable to create ZIP file $zip_name for boxalino data sync");
            return false;
        }

        // Create files
        $this->startXml($id);
        $zip->addFile($this->getArticles($id), self::ITEM_PROPERTIES_CSV);
        $zip->addFile($this->getBrands($id), self::ITEM_BRANDS_CSV);
        $zip->addFile($this->getCategories($id), self::CATEGORIES_CSV);
        $zip->addFile($this->getItemCategories($id), self::ITEM_CATEGORIES_CSV);
        if (count($this->getShopLocales($id)) > 1) {
            $zip->addFile($this->getTranslations($id), self::ITEM_TRANSLATIONS_CSV);
        }
        $zip->addFile($this->getCustomers($id), self::CUSTOMERS_CSV);
		$zip->addFile($this->getFacetValues($id), self::ITEM_FACETVALUES_CSV);
		$zip->addFile($this->getBlogs($id), self::ITEM_BLOGS_CSV);
        $zip->addFile($this->getTransactions($id), self::TRANSACTIONS_CSV);
		$zip->addFile($this->getStoreInfo($id), self::STORE_INFO_TXT);
        $zip->addFromString('properties.xml', $this->finishAndGetXml($id));
        $zip->close();

        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($this->xml->asXML());
        $saveXML = $dom->saveXML();
        file_put_contents($this->dirPath . 'properties.xml', $saveXML);

        $this->db->query('TRUNCATE `exports`');
        $this->db->query('INSERT INTO `exports` values(NOW())');
        return true;
    }

    /**
     * push the data feeds XML configuration file to the boxalino data intelligence
     *
     * @param int $id
     * @return string
     */
    protected function pushXml($id) {
        $dev = (bool) $this->getConfigurationByShopId($id, 'dev');
        $fields = array(
            'username'  => $this->getConfigurationByShopId($id, 'username'),
            'password'  => $this->getConfigurationByShopId($id, 'password'),
            'account'   => $this->getConfigurationByShopId($id, 'account'),
            'dev'       => $dev ? 'true' : 'false',
            'template' => 'standard_source',
            'xml'      => file_get_contents($this->dirPath . 'properties.xml')
        );
        return $this->pushFile(
            $dev ? self::URL_XML_DEV : self::URL_XML,
            $fields
        );
    }

    /**
     * push the data feed ZIP file to the boxalino data intelligence
     *
     * @param int $id
     * @return string
     */
    protected function pushZip($id) {
        $dev = $this->getConfigurationByShopId($id, 'dev');
        $fields = array(
            'username'  => $this->getConfigurationByShopId($id, 'username'),
            'password'  => $this->getConfigurationByShopId($id, 'password'),
            'account'   => $this->getConfigurationByShopId($id, 'account'),
            'dev'       => $dev ? 'true' : 'false',
            'delta'     => $this->delta ? 'true' : 'false',
            'data'      => $this->getCurlFile($this->dirPath . 'export.zip', 'application/zip'),
        );
        return $this->pushFile(
            $dev ? self::URL_ZIP_DEV : self::URL_ZIP,
            $fields
        );
    }

    /**
     * package file for inclusion into curl post fields
     *
     * this was introduced since the "@" notation is deprecated since php 5.5
     *
     * @param string $filename
     * @param string $type
     * @return CURLFile|string
     */
    protected function getCurlFile($filename, $type)
    {
        try {
            if (class_exists('CURLFile')) {
                return new CURLFile($filename, $type);
            }
        } catch(Exception $e) {}
        return "@$filename;type=$type";
    }

    /**
     * push POST fields to a URL, returning the response
     *
     * @param unknown $url
     * @param unknown $fields
     * @return string
     */
    protected function pushFile($url, $fields) {
        $s = curl_init();
        curl_setopt($s, CURLOPT_URL, $url);
        curl_setopt($s, CURLOPT_TIMEOUT, 35000);
        curl_setopt($s, CURLOPT_POST, true);
        curl_setopt($s, CURLOPT_ENCODING, '');
        curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($s, CURLOPT_POSTFIELDS, $fields);
        $responseBody = curl_exec($s);
        curl_close($s);
        return $responseBody;
    }

    /**
     * @param int $id
     * @return string
     */
    protected function getArticles($id)
    {
        $this->log->debug("start collecting articles for shop id $id");
        // property descriptions for XML configuration
        $this->propertyDescriptions[self::ITEM_PROPERTIES] = array(
            'source' => self::ITEM_PROPERTIES,
            'fields' => array(
                'item_id' => array('type' => 'id'),
                'product_id' => array('column' => 'item_id', 'params' => array(
                    'fieldParameter' => array('name' => 'multiValued', 'value' => 'false')
                )),
                'group_id' => array('params' => array(
                    'fieldParameter' => array('name' => 'multiValued', 'value' => 'false')
                )),
                'ordernumber',
                'mainnumber' => array('name' => 'asd'),
                'name' => array('type'=>'title'),
                'additionaltext' => array('type' => 'text'),
                'supplier',
                'tax',
                'pseudoprice' => array('type' => 'price'),
                'baseprice' => array('type' => 'number'),
                'active',
                'instock' => array('type' => 'number'),
                'stockmin' => array('type' => 'number'),
                'description' => array('type' => 'body'),
                'shippingtime',
                'added' => array('type' => 'date', 'params' => array(
                    'fieldParameter' => array('name' => 'multiValued', 'value' => 'false')
                )),
                'changed' => array('type' => 'date', 'params' => array(
                    'fieldParameter' => array('name' => 'multiValued', 'value' => 'false')
                )),
                'releasedate' => array('type' => 'date', 'params' => array(
                    'fieldParameter' => array('name' => 'multiValued', 'value' => 'false')
                )),
                'shippingfree',
                'topseller' => array('type' => 'number', 'params' => array(
                    'fieldParameter' => array('name' => 'multiValued', 'value' => 'false')
                )),
                'metaTitle',
                'keywords',
                'minpurchase' => array('type' => 'number'),
                'purchasesteps',
                'maxpurchase' => array('type' => 'number'),
                'purchaseunit',
                'referenceunit',
                'packunit',
                'unitID',
                'pricegroupID',
                'pricegroupActive',
                'laststock',
                'suppliernumber',
                'impressions',
                'sales' => array('type' => 'number', 'params' => array(
                    'fieldParameter' => array('name' => 'multiValued', 'value' => 'false')
                )),
                'esd',
                'weight' => array('type' => 'number'),
                'width' => array('type' => 'number'),
                'height' => array('type' => 'number'),
                'length' => array('type' => 'number'),
                'ean',
                'unit',
                'mediaId' => array('type' => 'number'),
                'property_values' => array('name' => 'property_values', 'column' => 'propertyValues', 'params' => array(
                    'fieldParameter' => array('name' => 'splitValues', 'value' => '|')
                ))
            )
        );

        // add source to XML configuration
        $sources = $this->xml->xpath('//sources');
        $sources = $sources[0];
        $source = $sources->addChild('source');
        $source->addAttribute('type', 'item_data_file');
        $source->addAttribute('id', self::ITEM_PROPERTIES);
        $source->addChild('file')->addAttribute('value', self::ITEM_PROPERTIES_CSV);
        $source->addChild('itemIdColumn')->addAttribute('value', 'item_id');
        $this->appendXmlOptions($source);

        // get all attributes
        $metaDataFactory = $this->getManager()->getMetadataFactory();
        $attributeFields = $metaDataFactory->getMetadataFor('Shopware\Models\Attribute\Article')->fieldNames;

        $attributeFields = array_flip($attributeFields);
        unset($attributeFields['id']);
        unset($attributeFields['articleDetailId']);
        unset($attributeFields['articleId']);
        $attributeFields = array_flip($attributeFields);

        $db = $this->db;

        // get the default customer group (no support for multiple customergroups yet)
        $sql = $db->select()
                  ->from('s_core_customergroups', array('id', 'groupkey', 'taxinput'))
                  ->where($this->qi('groupkey') . ' = ?', 'EK')
                  ->where($this->qi('mode') . ' = ?', 0);
        $customergroups = $db->fetchAll($sql);

        // declare common query building blocks
        $dot = $db->quote('.');
        $pipe = $db->quote('|');
        $empty = $db->quote('');
        $colon = $db->quote(':');
        $quoted0 = $db->quote(0);
        $quoted1 = $db->quote(1);
        $quoted2 = $db->quote(2);
        $quoted3 = $db->quote(3);
        $quoted100 = $db->quote(100);
        $zeroDate = $db->quote('0000-00-00');
        $zeroDateTime = $db->quote('0000-00-00 00:00:00');

        $fieldId = $this->qi('id');
        $fieldMain = $this->qi('main');
        $fieldName = $this->qi('name');
        $fieldPosition = $this->qi('position');
        $fieldArticleId = $this->qi('articleID');
        $fieldCategoryId = $this->qi('categoryID');

        $ai = $this->qi('ai');
        $cg = $this->qi('cg');
        $co = $this->qi('co');

        $aId = $this->qi('a.id');
        $dId = $this->qi('d.id');
        $tTax = $this->qi('t.tax');
        $adId = $this->qi('ad.id');
        $raId = $this->qi('ra.id');
        $radId = $this->qi('rad.id');
        $eFile = $this->qi('e.file');
        $dKind = $this->qi('d.kind');
        $aDatum = $this->qi('a.datum');
        $faValueId = $this->qi('fa.valueId');
        $faArticleId = $this->qi('fa.articleID');
        $saArticleId = $this->qi('sa.articleID');
        $aChangetime = $this->qi('a.changetime');
        $corArticleId = $this->qi('cor.article_id');
        $dReleasedate = $this->qi('d.releasedate');
        $aimpArticleId = $this->qi('aimp.articleID');
        $raMainDetailId = $this->qi('ra.main_detail_id');
        $radOrdernumber = $this->qi('ordernumber');
        $aimpImpressions = $this->qi('aimp.impressions');
        $saRelatedArticle = $this->qi('sa.relatedarticle');
        $aiArticleDetailId = $this->qi('ai.article_detail_id');

        // start putting together the main field list, special cases first
        $fields = array(
            'group_id' => 'id',
            'added' => new Zend_Db_Expr(
                "IF($aDatum = $zeroDate, $empty, $aDatum)"
            ),
            'changed' => new Zend_Db_Expr(
                "IF($aChangetime = $zeroDateTime, $empty, $aChangetime)"
            ),
            'filterGroupId' => 'filtergroupID',
            'configuratorsetID' => 'configurator_set_id',
        );

        // direct mapped fields
        $simpleFields = array(
            'active',
            'topseller',
            'metaTitle',
            'keywords',
            'pricegroupID',
            'pricegroupActive',
            'laststock',
            'name',
            'description',
            'description_long',
        );
        foreach ($simpleFields as $key) {
            $fields[$key] = $key;
        }

        // relations
        $relationFields = array(
            'similar'     => 'similar',
            'crosselling' => 'relationships',
        );
        foreach ($relationFields as $key => $table) {
            $innerSelect = $db->select()
                              ->from(array('sa' => 's_articles_' . $table), array())
                              ->joinInner(
                                array('ra' => 's_articles'),
                                "$raId = $saRelatedArticle",
                                array()
                              )
                              ->joinInner(
                                array('rad' => 's_articles_details'),
                                "$radId = $raMainDetailId",
                                new Zend_Db_Expr("GROUP_CONCAT(
                                    $radOrdernumber SEPARATOR $pipe
                                )")
                              )
                              ->where("$saArticleId = $aId");
            $fields[$key] = new Zend_Db_Expr("($innerSelect)");
        }

        // images
        $shop = $this->getManager()->getRepository('Shopware\Models\Shop\Shop')->getActiveDefault();
        $shop->registerResources(Shopware()->Bootstrap());
        $imagePath = $db->quote('http://'. $shop->getHost() . $shop->getBasePath()  . '/media/image/');
        $innerSelect = $db->select()
                          ->from(
                            's_articles_img',
                            new Zend_Db_Expr("GROUP_CONCAT(
                                CONCAT($imagePath, img, $dot, extension)
                                ORDER BY $fieldMain, $fieldPosition
                                SEPARATOR $pipe
                            )")
                          )
                          ->where("$fieldArticleId = $aId");
        $fields['images'] = new Zend_Db_Expr("($innerSelect)");

        // dynamic properties
        $innerSelect = $db->select()
                          ->from(
                            array('fa' => 's_filter_articles'),
                            new Zend_Db_Expr(
                                "GROUP_CONCAT($faValueId SEPARATOR $pipe)"
                            )
                          )
                          ->where("$faArticleId = $aId");
        $fields['propertyValues'] = new Zend_Db_Expr("($innerSelect)");

        // configurable properties
        $innerSelect = $db->select()
                          ->from(
                            array('ad' => 's_articles_details'),
                            new Zend_Db_Expr("GROUP_CONCAT(
                                CONCAT_WS(
                                    $colon, $cg.$fieldName, $co.$fieldName
                                ) SEPARATOR $pipe
                            )")
                          )
                          ->joinInner(
                            array('cor' => 's_article_configurator_option_relations'),
                            "$corArticleId = $adId",
                            array()
                          )
                          ->joinInner(
                            array('co' => 's_article_configurator_options'),
                            "$co.$fieldId = " . $this->qi('cor.option_id'),
                            array()
                          )
                          ->joinInner(
                            array('cg' => 's_article_configurator_groups'),
                            "$cg.$fieldId = " . $this->qi('co.group_id'),
                            array()
                          )
                          ->where("$adId = $dId")
                          ->group('ad.id');
        $fields['configuratorOptions'] = new Zend_Db_Expr("($innerSelect)");

        // base query
        $sql = $db->select()
                  ->from(array('a' => 's_articles'), $fields)
                  ->where($this->qi('a.mode') . ' = ?', 0)
                  ->where($this->qi('a.active') . ' = ?', 1)
                  ->group('d.id')
                  ->order(array('a.id', 'd.kind', 'd.id'));
        if ($this->delta) {
            $sql->where("$aChangetime > ?", $this->getLastDelta());
        }

        // details
        $fields = array(
            'item_id' => 'id',
            'releasedate' => new Zend_Db_Expr(
                "IF($dReleasedate = $zeroDate, $empty, $dReleasedate)"
            ),
        );
        $simpleFields = array(
            'ordernumber',
            'additionaltext',
            'instock',
            'stockmin',
            'shippingtime',
            'shippingfree',
            'minpurchase',
            'purchasesteps',
            'maxpurchase',
            'purchaseunit',
            'referenceunit',
            'packunit',
            'unitID',
            'suppliernumber',
            'sales',
            'weight',
            'width',
            'height',
            'length',
            'ean',
        );
		
        foreach ($simpleFields as $key) {
            $fields[$key] = $key;
        }
		
        $sql->join(
            array('d' => 's_articles_details'),
            $this->qi('d') . ".$fieldArticleId = $aId AND $dKind <> $quoted3",
            $fields
        );

        // mainnumber (!= ordernumber)
        $sql->join(
            array('d2' => 's_articles_details'),
            $this->qi('d2.id') . ' = ' . $this->qi('a.main_detail_id'),
            array('mainnumber' => 'ordernumber')
        );

        // attributes
        $fields = array();
        foreach ($attributeFields as $columnName => $field) {
            $fields['attr_' . $field] = $columnName;
            $this->propertyDescriptions[self::ITEM_PROPERTIES]['fields'][] = 'attr_' . $field;
        }
        $sql->joinLeft(
            array('at' => 's_articles_attributes'),
            $this->qi('at.articledetailsID') . " = $dId",
            $fields
        );

        // units
        $sql->joinLeft(
            array('u' => 's_core_units'),
            $this->qi('u.id') . ' = ' . $this->qi('d.unitID'),
            array('unit')
        );

        // taxes
        $sql->joinLeft(
            array('t' => 's_core_tax'),
            $this->qi('a.taxID') . ' = ' . $this->qi('t.id'),
            array('tax')
        );

        // suppliers
        $sql->joinLeft(
            array('s' => 's_articles_supplier'),
            $this->qi('a.supplierID') . ' = ' . $this->qi('s.id'),
            array('supplier' => 'id')
        );

        // esd
        $sql->joinLeft(
            array('e' => 's_articles_esd'),
            $this->qi('e.articledetailsID') . " = $dId",
            array('esd' => new Zend_Db_Expr(
                "IF($eFile IS NULL, $quoted0, $quoted1)"
            ))
        );

        // configuratortype
        $sql->joinLeft(
            array('acs' => 's_article_configurator_sets'),
            $this->qi('acs.id') . ' = ' . $this->qi('a.configurator_set_id'),
            array('configuratortype' => 'type')
        );

        // mediaId
        $sql->joinLeft(
            array('ai' => 's_articles_img'),
            "$ai.$fieldArticleId = $aId AND
            $aiArticleDetailId IS NULL AND
            $ai.$fieldMain = $quoted1",
            array('mediaId' => 'media_id')
        );

        // impressions
        $sql->joinLeft(
            array('aimp' => 's_statistics_article_impression'),
            "$aimpArticleId = $aId",
            array('impressions' => new Zend_Db_Expr("SUM($aimpImpressions)"))
        );

        // prices by customergroup (currently only of default group)
        if (!empty($customergroups)) {
            $p = $this->qi('p');
            $fieldFrom = $this->qi('from');
            $fieldPrice = $this->qi('price');
            $fieldPriceBase = $this->qi('baseprice');
            $fieldPriceGroup = $this->qi('pricegroup');
            $fieldPricePseudo = $this->qi('pseudoprice');
            $fieldArticleDetailsId = $this->qi('articledetailsID');
            foreach ($customergroups as $group) {
                $tableKey = 'p';
                $key = 'price';
                $taxFactor = '';
                if (!empty($group['taxinput'])) {
                    $taxFactor = " * ($quoted100 + $tTax) / $quoted100";
                }
                if ($group['groupkey'] == 'EK') {
                    $fields = array(
                        'pseudoprice' => new Zend_Db_Expr(
                            "ROUND($p.$fieldPricePseudo$taxFactor, $quoted2)"
                        ),
                        'baseprice' => new Zend_Db_Expr(
                            "ROUND($p.$fieldPriceBase$taxFactor, $quoted2)"
                        ),
                    );
                } else {
                    $tableKey .= $group['id'];
                    $key .= '_' . $group['groupkey'];
                    $fields = array();
                }
                $this->propertyDescriptions[
                    self::ITEM_PROPERTIES
                ]['fields'][$key] = array('type' => 'discounted');

                $quotedTableKey = $this->qi($tableKey);
                $quotedGroupKey = $db->quote($group['groupkey']);
                $fields[$key] = new Zend_Db_Expr(
                    "ROUND($quotedTableKey.$fieldPrice$taxFactor, $quoted2)"
                );

                $sql->joinLeft(
                    array($tableKey => 's_articles_prices'),
                    "$quotedTableKey.$fieldArticleDetailsId = $dId AND
                    $quotedTableKey.$fieldPriceGroup = $quotedGroupKey AND
                    $quotedTableKey.$fieldFrom = $quoted1",
                    $fields
                );
            }
        }

        // reduce scope to items that are in the current (sub)shops languages
        $sql->join(
                array('ac' => 's_articles_categories'),
                $this->qi('ac.articleID') . " = $aId",
                array()
              )
              ->join(
                array('c' => 's_categories'),
                $this->qi('c.id') . ' = ' . $this->qi('ac.categoryID') .
                $this->getShopCategoryIds($id),
                array()
              );

        $stmt = $db->query($sql);
        $sql = null;
        $fields = null;

        // prepare file & stream results into it
        $file_name = $this->dirPath . self::ITEM_PROPERTIES_CSV;
        $this->openFile($file_name);

        $first = true;
        while ($row = $stmt->fetch()) {
            $row = $this->prepareArticleRow($row);

            if ($first) {
                $first = false;
                $this->addRowToFile(array_keys($row));
            }
            $this->addRowToFile($row);
        }
        $this->closeFile();

        return $file_name;
    }

    /**
     * @param int $id
     * @return string
     */
    protected function getBrands($id)
    {
        $this->log->debug("start collecting brands for shop id $id");
        // prepare XML configuration
        $this->propertyDescriptions[self::ITEM_BRANDS] = array(
            'source' => self::ITEM_BRANDS,
            'fields' => array()
        );
        $sources = $this->xml->xpath('//sources');
        $sources = $sources[0];
        $source = $sources->addChild('source');
        $source->addAttribute('type', 'item_data_file');
        $source->addAttribute('id', self::ITEM_BRANDS);
        $source->addChild('file')->addAttribute('value', self::ITEM_BRANDS_CSV);
        $source->addChild('itemIdColumn')->addAttribute('value', 'item_id');
        $this->appendXmlOptions($source);

        $properties = $this->xml->xpath('//properties');
        $properties = $properties[0];
        $property = $properties->addChild('property');
        $property->addAttribute('id', 'brand');
        $property->addAttribute('type', 'text');
        $transform = $property->addChild('transform');
        $logic = $transform->addChild('logic');
        $logic->addAttribute('source', self::ITEM_BRANDS);
        $logic->addAttribute('type', 'direct');
        $locales = $this->getShopLocales($id);
        foreach($locales as $locale) {
            $field = $logic->addChild('field');
            $field->addAttribute('language', $locale);
            $field->addAttribute('column', 'brand_' . $locale);
        }
        $property->addChild('params');

        // prepare query
        $db = $this->db;

        $sql = $db->select()
                  ->from(array('a' => 's_articles'), array())
                  ->join(
                    array('d' => 's_articles_details'),
                    $this->qi('d.articleID') . ' = ' . $this->qi('a.id') . ' AND ' .
                    $this->qi('d.kind') . ' <> ' . $db->quote(3),
                    array('id')
                  )
                  ->join(
                    array('asup' => 's_articles_supplier'),
                    $this->qi('asup.id') . ' = ' . $this->qi('a.supplierID'),
                    array('name')
                  )
                  ->join(
                    array('ac' => 's_articles_categories'),
                    $this->qi('ac.articleID') . ' = ' . $this->qi('a.id'),
                    array()
                  )
                  ->join(
                    array('c' => 's_categories'),
                    $this->qi('c.id') . ' = ' . $this->qi('ac.categoryID') .
                    $this->getShopCategoryIds($id),
                    array()
                  )
                  ->where($this->qi('a.active') . ' = ?', 1);
        $stmt = $db->query($sql);

        // prepare file & stream results into it
        $file_name = $this->dirPath . self::ITEM_BRANDS_CSV;
        $this->openFile($file_name);

        $headers = array('item_id');
        foreach($locales as $locale) {
            $headers[] = 'brand_' . $locale;
        }
        $this->addRowToFile($headers);

        while ($row = $stmt->fetch()) {
            $brand = array($row['id']);
            foreach($locales as $l) {
                $brand[] = $row['name'];
            }
            $this->addRowToFile($brand);
        }
        $this->closeFile();

        return $file_name;
    }

    /**
     * @param int $id
     * @return string
     */
    protected function getCategories($id)
    {
        $this->log->debug("start collecting categories for shop id $id");
        // prepare XML configuration
        $this->propertyDescriptions[self::CATEGORIES] = array(
            'source' => self::CATEGORIES,
            'fields' => array()
        );
        $sources = $this->xml->xpath('//sources');
        $sources = $sources[0];
        $source = $sources->addChild('source');
        $source->addAttribute('type', 'hierarchical');
        $source->addAttribute('id', self::CATEGORIES);
        $source->addChild('file')->addAttribute('value', self::CATEGORIES_CSV);
        $source->addChild('referenceIdColumn')->addAttribute('value', 'cat_id');
        $source->addChild('parentIdColumn')->addAttribute('value', 'parent_id');
        $labelColumns = $source->addChild('labelColumns');
        $locales = $this->getShopLocales($id);
        foreach($locales as $locale) {
            $label = $labelColumns->addChild('language');
            $label->addAttribute('name', $locale);
            $label->addAttribute('value', 'value_' . $locale);
        }
        $this->appendXmlOptions($source);
		
		$db = $this->db;
        $sql = $db->select()
                  ->from(array('c' => 's_core_shops'), array('id', 'category_id'));
        $stmt = $db->query($sql);
		$language_root_category = array();
		while ($row = $stmt->fetch()) {
            $language_root_category[strtolower($row['category_id'])] = $row;
        }

        // prepare queries
        $db = $this->db;
        $sql = $db->select()
                  ->from(array('c' => 's_categories'), array('id', 'parent', 'description', 'path'))
                  ->where($this->qi('c.path') . ' IS NOT NULL')
                  ->where($this->qi('c.id') . ' <> ?', 1);
        $stmt = $db->query($sql . $this->getShopCategoryIds($id));

        // prepare file & stream results into it
        $file_name = $this->dirPath . self::CATEGORIES_CSV;
        $this->openFile($file_name);

        $headers = array('cat_id', 'parent_id');
        foreach($locales as $locale) {
            $headers[] = 'value_' . $locale;
        }
        $headers[] = 'shop_id';
        $headers[] = 'language';
        $this->addRowToFile($headers);
		
		while ($row = $stmt->fetch()) {
            $category = array($row['id'], $row['parent']);
            foreach($locales as $locale) {
                $category[] = $row['description'];
            }
            $parts = explode('|', $row['path']);
            $rootCategory = $parts[sizeof($parts)-2];
            $category[] = $language_root_category[$rootCategory]['id'];
            $category[] = $locales[$language_root_category[$rootCategory]['id']];
            $this->addRowToFile($category);
        }
        $this->closeFile();

        return $file_name;
    }
	
	protected function getStoreInfo($id) {
		// prepare file & stream results into it
        $file_name = $this->dirPath . self::STORE_INFO_TXT;
        $this->openFile($file_name);
		$string = "This store is the store id: " . $id;
        $this->addStringToFile($string);
        $this->closeFile();

        return $file_name;
	}

	
	/*return string of the filter values, article ids and option ids*/
	protected function getFacetValues($id)
    {
		// prepare XML configuration for "<source>" tag
        $this->log->debug("start collecting filter values for shop id $id");
        $this->propertyDescriptions[self::ITEM_FACETVALUES] = array(
            'source' => self::ITEM_FACETVALUES,
            'fields' => array()
        );
		
        $sources = $this->xml->xpath('//sources');
        $sources = $sources[0];
        $source = $sources->addChild('source');
        $source->addAttribute('type', 'item_data_file');
        $source->addAttribute('id', self::ITEM_FACETVALUES);
        $source->addChild('file')->addAttribute('value', self::ITEM_FACETVALUES_CSV);
        $source->addChild('itemIdColumn')->addAttribute('value', 'item_id');
        //$source->addChild('optionIdColumn')->addAttribute('value', 'option_id');
		//$source->addChild('valueColumn')->addAttribute('value', 'value');
        //locales
		$this->appendXmlOptions($source);
		$db = $this->db;
		$sqlOptionID = $db->select()
                  ->from(array('sFilVal' => 's_filter_values'), array('sFilVal.optionID'))
				  ->group('sFilVal.optionID')
				  ->order('sFilVal.optionID');
	    $sqlCounterOptionID = $db->fetchAll($sqlOptionID);
		
		
		
		
   
		// prepare XML configuration for "<properties>" tag
        $properties = $this->xml->xpath('//properties');
        $properties = $properties[0];
	    foreach ($sqlCounterOptionID as $columnName => $fields) {
		
        $property = $properties->addChild('property');
        $property->addAttribute('id', 'optionID_' . $fields[optionID]);
        $property->addAttribute('type', 'text');
        $transform = $property->addChild('transform');
        $logic = $transform->addChild('logic');
        $logic->addAttribute('source', self::ITEM_FACETVALUES);
        $logic->addAttribute('type', 'direct');
		
        $locales = $this->getShopLocales($id);
        foreach($locales as $locale) {
            $field = $logic->addChild('field');
            $field->addAttribute('language', $locale);
            $field->addAttribute('column', 'value_' . $locale);
        }
		
        $forFieldParameter = $property->addChild('params');
		$paramslogic = $forFieldParameter->addChild('fieldParameter');
		$paramslogic->addAttribute('name', 'eligibility_condition');
        $paramslogic->addAttribute('value', 'option_id=' . $fields[optionID]);
		
		
		}
	
	
	
	
		
		
		$sql = $db->select()
                  ->from(array('t' => 's_core_translations'), array('t.objectlanguage', 't.objectKey', 't.objectdata'))
				  ->where('t.objecttype = "propertyvalue"');
		$stmt = $db->query($sql);
		
		
		$localizedFacets = array();
		while ($row = $stmt->fetch()) {
        	if(!isset($localizedFacets[$row['objectlanguage']])) {
				$localizedFacets[$row['objectlanguage']] = array();
			}
			$localizedFacets[$row['objectlanguage']][$row['objectKey']] = unserialize($row['objectdata']);
		}

	
        // prepare queries
        
        $sql = $db->select()
                  ->from(array('sFilVal' => 's_filter_values'), array('sArtDetail.id', 'sFilVal.optionID', 'sFilVal.value', 'sFilVal.id as fid'))
				  ->order('sArtDetail.id ASC')
				  ->join(array('sFilArt' => 's_filter_articles'), 'sFilVal.id = sFilArt.valueID')
				  ->join(array('sArt' => 's_articles'), 'sArt.id = sFilArt.articleID')
				  ->join(array('sArtDetail' => 's_articles_details'), 'sArtDetail.articleID = sArt.id');
		$stmt = $db->query($sql);
		
        // prepare file & stream results into it
        $file_name = $this->dirPath . self::ITEM_FACETVALUES_CSV;
        $this->openFile($file_name);
        $headers = array('item_id', 'option_id');
		$locales = $this->getShopLocales($id);
		
		foreach($locales as $languageId => $languageLabel) {
			$headers[] = 'value_' . $languageLabel;
		}
        $this->addRowToFile($headers);
		
        while ($row = $stmt->fetch()) {
            $facetValueslocal = array($row['id'], $row['optionID']);
			
			foreach($locales as $languageId => $languageLabel) {
				
				$facetValueslocal[] = isset($localizedFacets[$languageId][$row['fid']]) ? $localizedFacets[$languageId][$row['fid']][optionValue] : $row['value'];
			
			
			}
            
				
			
            $this->addRowToFile($facetValueslocal);
        }
        $this->closeFile();

        return $file_name;
    }
	
	
	
	
    /**
     * @param int $id
     * @return string
     */
    protected function getItemCategories($id)
    {
        $this->log->debug("start collecting item categories for shop id $id");
        // prepare XML configuration
        $this->propertyDescriptions[self::ITEM_CATEGORIES] = array(
            'source' => self::ITEM_CATEGORIES,
            'fields' => array(
                'category' => array('type' => 'hierarchical', 'column' => 'cat_id', 'logical_type' => 'reference', 'params' => array(
                    'referenceSource' => array('value'=>'categories')
                ))
            )
        );
        $sources = $this->xml->xpath('//sources');
        $sources = $sources[0];
        $source = $sources->addChild('source');
        $source->addAttribute('type', 'item_data_file');
        $source->addAttribute('id', self::ITEM_CATEGORIES);
        $source->addChild('file')->addAttribute('value', self::ITEM_CATEGORIES_CSV);
        $source->addChild('itemIdColumn')->addAttribute('value', 'item_id');
        $this->appendXmlOptions($source);

        $db = $this->db;

        // get category per item
        $sql = $db->select()
                  ->from(array('ac' => 's_articles_categories'), array('categoryID'))
                  ->join(
                    array('d' => 's_articles_details'),
                    $this->qi('d.articleID') . ' = ' . $this->qi('ac.articleID') . ' AND ' .
                    $this->qi('d.kind') . ' <> ' . $db->quote(3),
                    array('id')
                  )
                  ->join(
                    array('c' => 's_categories'),
                    $this->qi('c.id') . ' = ' . $this->qi('ac.categoryID') .
                    $this->getShopCategoryIds($id),
                    array()
                  );
        $stmt = $db->query($sql);

        // prepare file & stream results into it
        $file_name = $this->dirPath . self::ITEM_CATEGORIES_CSV;
        $this->openFile($file_name);
        $this->addRowToFile(array('item_id', 'cat_id'));

        while ($row = $stmt->fetch()) {
            $this->addRowToFile(array($row['id'], $row['categoryID']));
        }
        $this->closeFile();

        return $file_name;
    }

    /**
     * @param int $id
     * @return string
     */
    protected function getTranslations($id)
    {
        $this->log->debug("start collecting translations for shop id $id");
        // prepare XML configuration
        $this->propertyDescriptions[self::ITEM_TRANSLATIONS] = array(
            'source' => self::ITEM_TRANSLATIONS,
            'fields' => array(
                'name' => array('type' => 'title'),
                'description' => array('type' => 'body'),
                'additionaltext' => array('type' => 'text'),
            )
        );
        unset($this->propertyDescriptions[self::ITEM_PROPERTIES]['fields']['name']);
        unset($this->propertyDescriptions[self::ITEM_PROPERTIES]['fields']['description']);
        unset($this->propertyDescriptions[self::ITEM_PROPERTIES]['fields']['additionaltext']);

        $sources = $this->xml->xpath('//sources');
        $sources = $sources[0];
        $source = $sources->addChild('source');
        $source->addAttribute('type', 'item_data_file');
        $source->addAttribute('id', self::ITEM_TRANSLATIONS);
        $source->addChild('file')->addAttribute('value', self::ITEM_TRANSLATIONS_CSV);
        $source->addChild('itemIdColumn')->addAttribute('value', 'item_id');
        $this->appendXmlOptions($source);

        // prepare variables
        $locales = $this->getShopLocales($id);
        $db = $this->db;

        $article = $db->quote('article');
        $variant = $db->quote('variant');

        $fieldIsoCode = $this->qi('isocode');
        $fieldLocale = $this->qi('locale');
        $fieldObjectKey = $this->qi('objectkey');
        $fieldObjectType = $this->qi('objecttype');
        $fieldObjectLanguage = $this->qi('objectlanguage');

        $aId = $this->qi('a.id');
        $dId = $this->qi('d.id');
        $acArticleId = $this->qi('ac.articleID');

        // prepare query
        $sql = $db->select()
                  ->from(
                    array('ac' => 's_articles_categories'),
                    array()
                  )
                  ->join(
                    array('d' => 's_articles_details'),
                    $this->qi('d.articleID') . " = $acArticleId AND " .
                    $this->qi('d.kind') . ' <> ' . $db->quote(3),
                    array('id', 'additionaltext')
                  )
                  ->join(
                    array('a' => 's_articles'),
                    "$aId = $acArticleId AND " .
                    $this->qi('a.active') . ' = ' . $db->quote(1),
                    array(
                        'name',
                        'description',
                        'description_long',
                    )
                  )
                  ->join(
                    array('c' => 's_categories'),
                    $this->qi('c.id') . ' = ' . $this->qi('ac.categoryID') .
                    $this->getShopCategoryIds($id),
                    array()
                  )
                  ->group('d.id');

        // translations as serialized PHP objects
        $allLocales = $this->getLocales();
        foreach ($locales as $shopId => $locale) {
            $tableKey1 = 'tm_' . $locale;
            $tableKey2 = 'ta_' . $locale;
            $tableKey3 = 'td_' . $locale;
            $quotedTableKey1 = $this->qi($tableKey1);
            $quotedTableKey2 = $this->qi($tableKey2);
            $quotedTableKey3 = $this->qi($tableKey3);

            if (version_compare(Shopware::VERSION, '5.1.0', '>=')) {
                $articleId = $this->qi('articleID');
                $languageId = $this->qi('languageID');
                $sql->joinLeft(
                        array($tableKey2 => 's_articles_translations'),
                        "$quotedTableKey2.$articleId = $acArticleId AND
                        $quotedTableKey2.$languageId = $shopId",
                        array(
                            'name_' . $locale => 'name',
                            'description_' . $locale => 'description',
                            'description_long_' . $locale => 'description_long',
                            'article_translation_' . $locale => new Zend_Db_Expr('NULL'),
                            'detail_translation_' . $locale => new Zend_Db_Expr('NULL'),
                        )
                    );
            } else {
                $quotedLanguage = $db->quote($allLocales[$shopId]['locale_id']);
                $sql->joinLeft(
                        array($tableKey1 => 's_core_multilanguage'),
                        "$quotedTableKey1.$fieldLocale = $quotedLanguage",
                        array()
                    )
                    ->joinLeft(
                        array($tableKey2 => 's_core_translations'),
                        "$quotedTableKey2.$fieldObjectKey = $acArticleId AND
                        $quotedTableKey2.$fieldObjectType = $article AND
                        $quotedTableKey2.$fieldObjectLanguage = $quotedTableKey1.$fieldIsoCode",
                        array('article_translation_' . $locale => 'objectdata')
                    )
                    ->joinLeft(
                        array($tableKey3 => 's_core_translations'),
                        "$quotedTableKey3.$fieldObjectKey = $dId AND
                        $quotedTableKey3.$fieldObjectType = $variant AND
                        $quotedTableKey3.$fieldObjectLanguage = $quotedTableKey1.$fieldIsoCode",
                        array('detail_translation_' . $locale => 'objectdata')
                    );
            }
        }
        $stmt = $db->query($sql);

        // prepare file & stream results into it
        $file_name = $this->dirPath . self::ITEM_TRANSLATIONS_CSV;
        $this->openFile($file_name);

        $headers = array('item_id');
        foreach($locales as $locale) {
            $headers[] = 'name_' . $locale;
            $headers[] = 'description_' . $locale;
            $headers[] = 'additionaltext_' . $locale;
        }
        $this->addRowToFile($headers);

        while ($row = $stmt->fetch()) {
            $translation = array('item_id' => $row['id']);

            foreach ($locales as $locale) {
                foreach ($this->translationFields as $key) {
                    if (!array_key_exists($key . '_' . $locale, $row)) {
                        $row[$key . '_' . $locale] = '';
                    }
                }

                $objectdata = null;
                if (!empty($row['article_translation_' . $locale])) {
                    $objectdata = unserialize($row['article_translation_' . $locale]);
                } elseif (!empty($row['detail_translation_' . $locale])) {
                    $objectdata = unserialize($row['detail_translation_' . $locale]);
                }

                if (!empty($objectdata)) {
                    foreach ($objectdata as $key => $value) {
                        if (array_key_exists($key, $this->translationFields) && strlen($value)) {
                            $row[$this->translationFields[$key] . '_' . $locale] = $value;
                        }
                    }
                }
            }

            foreach ($locales as $locale) {
                $translation['name_' . $locale] = $row['name_' . $locale];
                $translation['description_' . $locale] = trim(
                    $row['description_' . $locale] . PHP_EOL . PHP_EOL .
                    $row['description_long_' . $locale]
                );
                $translation['additionaltext_' . $locale] = $row['additionaltext_' . $locale];

                // fall back to untranslated values if needed
                if (empty($translation['name_' . $locale])) {
                    $translation['name_' . $locale] = $row['name'];
                }
                if (empty($translation['description_' . $locale])) {
                    $translation['description_' . $locale] = trim(
                        $row['description'] . PHP_EOL . PHP_EOL .
                        $row['description_long']
                    );
                }
                if (empty($translation['additionaltext_' . $locale])) {
                    $translation['additionaltext_' . $locale] = $row['additionaltext'];
                }
            }

            $this->addRowToFile($translation);
        }
        $this->closeFile();

        return $file_name;
    }
	
	protected function getBlogs($id) {

        $db = $this->db;
		$this->log->debug("start collecting customers for shop id $id");
        $headers = array('id', 'title', 'author_id', 'active', 'short_description', 'description', 'views', 'display_date', 'category_id', 'template', 'meta_keywords', 'meta_description', 'meta_title', 'assigned_articles', 'tags');

		$sources = $this->xml->xpath('//sources');
        $sources = $sources[0];
        $source = $sources->addChild('source');
        $source->addAttribute('type', 'item_data_file');
        $source->addAttribute('id', self::ITEM_BLOGS);
        $source->addAttribute('additional_item_source', 'true');
        $source->addChild('file')->addAttribute('value', self::ITEM_BLOGS_CSV);
        $source->addChild('itemIdColumn')->addAttribute('value', 'id');
        $this->appendXmlOptions($source);

        // prepare XML configuration for "<properties>" tag
        $properties = $this->xml->xpath('//properties');
        $properties = $properties[0];
	    foreach ($headers as $columnName) {
			$property = $properties->addChild('property');
			$property->addAttribute('id', 'blog_' . $columnName);
			$property->addAttribute('type', 'string');
			$transform = $property->addChild('transform');
			$logic = $transform->addChild('logic');
			$logic->addAttribute('source', self::ITEM_BLOGS);
			$logic->addAttribute('type', 'direct');

			$field = $logic->addChild('field');
			$field->addAttribute('column', $columnName);

			$forFieldParameter = $property->addChild('params');
		}

        // prepare queries
        $sql = $db->select()
                  ->from(array('b' => 's_blog'), 
                         array('item_id' => new Zend_Db_Expr("CONCAT('blog_', b.id)"),
                               'b.title','b.author_id','b.active', 
                               'b.short_description','b.description','b.views', 
                               'b.display_date','b.category_id','b.template', 
                               'b.meta_keywords','b.meta_keywords','b.meta_description','b.meta_title', 
                               'assigned_articles' => new Zend_Db_Expr("GROUP_CONCAT(bas.article_id)"),
                               'tags' => new Zend_Db_Expr("GROUP_CONCAT(bt.name)")
                               )
                         )
                  ->joinLeft(array('bas' => 's_blog_assigned_articles'), 'bas.blog_id = b.id')
                  ->joinLeft(array('bt' => 's_blog_tags'), 'bt.blog_id = b.id')
                  ->group('b.id');
		$stmt = $db->query($sql);

        // prepare file & stream results into it
        $file_name = $this->dirPath . self::ITEM_BLOGS_CSV;
        $this->openFile($file_name);
        $this->addRowToFile($headers);

        while ($row = $stmt->fetch()) {

            $this->addRowToFile($row);

        }
		$this->closeFile();

		return $file_name;
	}

    /**
     * @param int $id
     * @return string
     */
    protected function getCustomers($id) {
        $this->log->debug("start collecting customers for shop id $id");
        $headers = array('id', 'customer_id', 'public_id', 'country', 'zip', 'dob', 'gender', 'language');
        $should_export_email = $this->getConfigurationByShopId($id, 'email');
        if ($should_export_email) {
            $headers[] = 'email';
        }

        // prepare XML configuration
        $containers = $this->xml->xpath('//containers');
        $containers = $containers[0];
        $customers = $containers->addChild('container');
        $customers->addAttribute('id', 'customers');
        $customers->addAttribute('type', 'customers');

        $sources = $customers->addChild('sources');
        $source = $sources->addChild('source');
        $source->addAttribute('id', self::CUSTOMERS);
        $source->addAttribute('type', 'item_data_file');
        $source->addChild('file')->addAttribute('value', self::CUSTOMERS_CSV);
        $source->addChild('itemIdColumn')->addAttribute('value', 'customer_id');
        $this->appendXmlOptions($source);

        $properties = $customers->addChild('properties');
        foreach ($headers as $prop) {
            $type = 'string';
            $column = $prop;
            switch($prop) {
                case 'id':
                    $type = 'id';
                    break;
                case 'dob':
                    $type = 'date';
                    break;
            }

            $property = $properties->addChild('property');
            $property->addAttribute('id', $prop);
            $property->addAttribute('type', $type);

            $transform = $property->addChild('transform');
            $logic = $transform->addChild('logic');
            $logic->addAttribute('source', 'customer_vals');
            $logic->addAttribute('type', 'direct');
            $logic->addChild('field')->addAttribute('column', $column);
            $property->addChild('params');
        }

        $db = $this->db;

        // get all customers
        $sql = $db->select()
                  ->from(
                    array('u' => 's_user'),
                    array('id')
                  )
                  ->joinLeft(
                    array('b' => 's_user_billingaddress'),
                    $this->qi('b.userID') . ' = ' . $this->qi('u.id'),
                    array(
                        'public_id' => 'customernumber',
                        'zip' => 'zipcode',
                        'dob' => 'birthday',
                        'gender' => 'salutation',
                    )
                  )
                  ->joinLeft(
                    array('c' => 's_core_countries'),
                    $this->qi('c.id') . ' = ' . $this->qi('b.countryID'),
                    array('country' => 'countryiso')
                  )
                  ->joinLeft(
                    array('l' => 's_core_locales'),
                    $this->qi('l.id') . ' = ' . $this->qi('u.language'),
                    array('language' => 'locale')
                  )
                  ->where($this->qi('u.subshopID') . ' = ?', $id);

        if ($should_export_email) {
            $sql->columns('email', 'u');
        }

        // prepare file & stream results into it
        $file_name = $this->dirPath . self::CUSTOMERS_CSV;
        $this->openFile($file_name);
        sort($headers);
        $this->addRowToFile($headers);

        $stmt = $db->query($sql);
        while ($row = $stmt->fetch()) {
            $row['customer_id'] = $row['id'];
            ksort($row);
            $this->addRowToFile($row);
        }
        $this->closeFile();

        return $file_name;
    }

    /**
     * @param int $id
     * @return string
     */
    protected function getTransactions($id) {
        $this->log->debug("start collecting transactions for shop id $id");
        // prepare XML configuration
        $containers = $this->xml->xpath('//containers');
        $containers = $containers[0];
        $transactions = $containers->addChild('container');
        $transactions->addAttribute('id', 'transactions');
        $transactions->addAttribute('type', 'transactions');

        $sources = $transactions->addChild('sources');
        $source = $sources->addChild('source');
        $source->addAttribute('id', self::TRANSACTIONS);
        $source->addAttribute('type', 'transactions');

        $source->addChild('file')->addAttribute('value', self::TRANSACTIONS_CSV);
        $source->addChild('orderIdColumn')->addAttribute('value', 'order_id');
        $customerIdColumn = $source->addChild('customerIdColumn');
        $customerIdColumn->addAttribute('value', 'customer_id');
        $customerIdColumn->addAttribute('customer_property_id', 'customer_id');
        $productIdColumn = $source->addChild('productIdColumn');
        $productIdColumn->addAttribute('value', 'product_id');
        $productIdColumn->addAttribute('product_property_id', 'group_id');
        $source->addChild('productListPriceColumn')->addAttribute('value', 'price');
        $source->addChild('productDiscountedPriceColumn')->addAttribute('value', 'discounted_price');
        $source->addChild('totalOrderValueColumn')->addAttribute('value', 'total_order_value');
        $source->addChild('shippingCostsColumn')->addAttribute('value', 'shipping_costs');
        $source->addChild('orderReceptionDateColumn')->addAttribute('value', 'order_date');
        $source->addChild('orderConfirmationDateColumn')->addAttribute('value', 'confirmation_date');
        $source->addChild('orderShippingDateColumn')->addAttribute('value', 'shipping_date');
        $source->addChild('orderStatusColumn')->addAttribute('value', 'status');

        $this->appendXmlOptions($source);

        $db = $this->db;

        // get all transactions
        $quoted2 = $db->quote(2);
        $oInvoiceAmount = $this->qi('o.invoice_amount');
        $oInvoiceShipping = $this->qi('o.invoice_shipping');
        $oCurrencyFactor = $this->qi('o.currencyFactor');
        $dPrice = $this->qi('d.price');
        $sql = $db->select()
                  ->from(
                    array('o' => 's_order'),
                    array(
                        'order_id' => 'ordernumber',
                        'customer_id' => 'userID',
                        'total_order_value' => new Zend_Db_Expr(
                            "ROUND($oInvoiceAmount * $oCurrencyFactor, $quoted2)"
                        ),
                        'shipping_costs' => new Zend_Db_Expr(
                            "ROUND($oInvoiceShipping * $oCurrencyFactor, $quoted2)"
                        ),
                        'order_date' => 'ordertime',
                        'confirmation_date' => 'cleareddate',
                        'status' => 'status',
                    )
                  )
                  ->join(
                    array('d' => 's_order_details'),
                    $this->qi('d.orderID') . ' = ' . $this->qi('o.id'),
                    array(
                        'product_id' => 'articleID',
                        'price' => new Zend_Db_Expr(
                            "ROUND($dPrice * $oCurrencyFactor, $quoted2)"
                        ),
                        'shipping_date' => 'releasedate',
                        'quantity' => 'quantity',
                    )
                  )
                  ->where($this->qi('o.subshopID') . ' = ?', $id);

        // transactions are always incremental, except by configuration overide
        if ($this->getConfigurationByShopId($id, 'increment')) {
            $sql->where($this->qi('o.ordertime') . ' >= ?', $this->getLastDelta());
        }

        // prepare file & stream results into it
        $file_name = $this->dirPath . self::TRANSACTIONS_CSV;
        $this->openFile($file_name);

        $headers = array(
            'order_id',
            'customer_id',
            'product_id',
            'price',
            'discounted_price',
            'quantity',
            'total_order_value',
            'shipping_costs',
            'order_date',
            'confirmation_date',
            'shipping_date',
            'status',
        );
        sort($headers);
        $this->addRowToFile($headers);

        $stmt = $db->query($sql);
        while ($row = $stmt->fetch()) {
            // @note list price at the time of the order is not stored, only the final price
            $row['discounted_price'] = $row['price'];
            ksort($row);
            $this->addRowToFile($row);
        }
        $this->closeFile();

        return $file_name;
    }

    protected function openFile($fileName)
    {
        @unlink($fileName);
        return $this->fileHandle = fopen($fileName, 'a');
    }

    protected function addRowToFile($row)
    {
        return fputcsv($this->fileHandle, $row, self::XML_DELIMITER, self::XML_ENCLOSURE);
    }

    protected function addStringToFile($string)
    {
        return fwrite($this->fileHandle, $string);
    }

    protected function closeFile()
    {
        return fclose($this->fileHandle);
    }

    /**
     * @param array $row
     * @return array
     */
    protected function prepareArticleRow($row)
    {
        if (empty($row['configuratorsetID'])) {
            $row['mainnumber'] = '';
            $row['additionaltext'] = '';
        }
        return $row;
    }

    /**
     * @return Doctrine\ORM\Mapping\ClassMetadataFactory
     */
    protected function getClassMetadataFactoryName()
    {
        if (!isset($this->_attributes['classMetadataFactoryName'])) {
            $this->_attributes['classMetadataFactoryName'] = '\Doctrine\ORM\Mapping\ClassMetadataFactory';
        }

        return $this->_attributes['classMetadataFactoryName'];
    }

    /**
     * @return \Shopware\Components\Model\ModelManager
     */
    protected function getEntityManager()
    {
        return Shopware()->Models();
    }

    /**
     * @return Enlight_Controller_Request_RequestHttp
     */
    protected function Request()
    {
        return $this->request;
    }

    /**
     * @return \Shopware\Components\Model\ModelManager
     */
    protected function getManager()
    {
        if ($this->manager === null) {
            $this->manager = Shopware()->Models();
        }
        return $this->manager;
    }

    /**
     * get the all active main (sub)shop ids
     *
     * @return array
     */
    protected function getMainShopIds() {
        $db = $this->db;
        $sql = $db->select()
                  ->from('s_core_shops', array('id'))
                  ->where($this->qi('main_id') . ' IS NULL')
                  ->where($this->qi('active') . ' = ?', 1);
        return $db->fetchCol($sql);
    }

    /**
     * get a shop specific configuration setting
     *
     * @param int $id
     * @param string $key
     * @return bool|string
     */
    protected function getConfigurationByShopId($id, $key) {
        if (!array_key_exists($id, $this->config)) {
            $config = array(
                'shop' => Shopware()->Models()->find('Shopware\\Models\\Shop\\Shop', $id),
                'db'   => $this->db,
            );
            $scopeConfig = new \Shopware_Components_Config($config);

            $account = $scopeConfig->get('boxalino_account');
            $this->config[$id] = array(
                'enabled'  => (bool) $scopeConfig->get('boxalino_export', false) && strlen($account),
                'account'  => $scopeConfig->get('boxalino_account'),
                'username' => $scopeConfig->get('boxalino_form_username'),
                'password' => $scopeConfig->get('boxalino_form_password'),
                'dev'      => (bool) $scopeConfig->get('boxalino_dev', true),
                'email'    => (bool) $scopeConfig->get('boxalino_customer_email', false),
                'increment'=> (bool) $scopeConfig->get('boxalino_transaction_style', true),
            );
        }
        return $this->config[$id][$key];
    }

    /**
     * get locale information for every shop
     *
     * @return array
     */
    protected function getLocales() {
        if (count($this->locales) == 0) {
            $db = $this->db;
            $sql = $db->select()
                      ->from(
                        array('s' => 's_core_shops'),
                        array('shop_id' => 'id')
                      )
                      ->join(
                        array('l' => 's_core_locales'),
                        'l.id = s.locale_id',
                        array('locale' => 'locale', 'locale_id' => 'id')
                      )
                      ->order('s.default DESC');
            foreach ($db->fetchAll($sql) as $l) {
                $position = strpos($l['locale'], '_');
                if ($position !== false) {
                    $l['locale'] = substr($l['locale'], 0, $position);
                }
                $this->locales[$l['shop_id']] = $l;
            }
        }
        return $this->locales;
    }

    /**
     * get all locales for this (sub)shop
     *
     * @param int $id
     * @return array
     */
    protected function getShopLocales($id) {
        if (!array_key_exists($id, $this->languages)) {
            $db = $this->db;
            $sql = $db->select()
                      ->from('s_core_shops', array('id'))
                      ->where($this->qi('id') . ' = ?', $id)
                      ->orWhere($this->qi('main_id') . ' = ?', $id);

            $locales = $this->getLocales();
            $this->languages[$id] = array();
            foreach ($db->fetchCol($sql) as $langShopId) {
                $this->languages[$id][$langShopId] = $locales[$langShopId]['locale'];
            }
        }
        return $this->languages[$id];
    }

    /**
     * get category IDs for this (sub)shop
     *
     * @param int $id
     * @return array
     */
    protected function getShopCategoryIds($id) {
        if (!array_key_exists($id, $this->rootCategories)) {
            $db = $this->db;
            $sql = $db->select()
                      ->from('s_core_shops', array('category_id'))
                      ->where($this->qi('id') . ' = ?', $id)
                      ->orWhere($this->qi('main_id') . ' = ?', $id);

            $cPath = $this->qi('c.path');
            $catIds = array();
            foreach ($db->fetchCol($sql) as $categoryId) {
                $catIds[] = "$cPath LIKE " . $db->quote("%|$categoryId|%");
            }
            if (count($catIds)) {
                $this->rootCategories[$id] = ' AND (' . implode(' OR ', $catIds) . ')';
            } else {
                $this->rootCategories[$id] = '';
            }
        }
        return $this->rootCategories[$id];
    }

    /**
     * @return string
     */
    protected function getLastDelta() {
        if (empty($this->deltaLast)) {
            $this->deltaLast = '1950-01-01 12:00:00';
            $db = $this->db;
            $sql = $db->select()
                      ->from('exports', array('export_date'))
                      ->limit(1);
            $stmt = $db->query($sql);
            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch();
                $this->deltaLast = $row['export_date'];
            }
        }
        return $this->deltaLast;
    }

    /**
     * wrapper to quote database identifiers
     *
     * @param  string $identifier
     * @return string
     */
    protected function qi($identifier) {
        return $this->db->quoteIdentifier($identifier);
    }

    /**
     * initialize the configuration XML
     *
     * @param int $id
     * @return void
     */
    protected function startXml($id)
    {
        $this->xml = new SimpleXMLElement('<root/>');
        $languages = $this->xml->addChild('languages');

        foreach ($this->getShopLocales($id) as $lang) {
            $language = $languages->addChild('language');
            $language->addAttribute('id', $lang);
        }

        $containers = $this->xml->addChild('containers');
        $productsContainer = $containers->addChild('container');
        $productsContainer->addAttribute('id', 'products');
        $productsContainer->addAttribute('type', 'products');

        $sources = $productsContainer->addChild('sources');
        $properties = $productsContainer->addChild('properties');
    }

    /**
     * append settings to given XML element
     *
     * @param SimpleXMLElement $xml
     * @return void
     */
    protected function appendXmlOptions(SimpleXMLElement &$xml)
    {
        $xml->addChild('format')->addAttribute('value', self::XML_FORMAT);
        $xml->addChild('encoding')->addAttribute('value', self::XML_ENCODE);
        $xml->addChild('delimiter')->addAttribute('value', self::XML_DELIMITER);
        $xml->addChild('enclosure')->addAttribute('value', self::XML_ENCLOSURE);
        $xml->addChild('escape')->addAttribute('value', self::XML_ESCAPE);
        $xml->addChild('lineSeparator')->addAttribute('value', self::XML_NEWLINE);
    }

    /**
     * write settings into configuration XML and return it
     *
     * @param int $id
     * @return string
     */
    protected function finishAndGetXml($id)
    {
        $properties = $this->xml->xpath('//properties');
        $properties = $properties[0];

        foreach ($this->propertyDescriptions as $data) {
            $itemFields = $data['fields'];
            foreach ($itemFields as $key => $fieldDesc) {
                if (is_string($fieldDesc)) {
                    $fieldDesc = array(
                        'name' => $fieldDesc,
                        'type' => 'string'
                    );
                } else {
                    $fieldDesc['name'] = $key;
                }
                if (!isset($fieldDesc['type']))
                    $fieldDesc['type'] = 'string';
                if (!isset($fieldDesc['logical_type']))
                    $fieldDesc['logical_type'] = 'direct';
                if (!isset($fieldDesc['column']))
                    $fieldDesc['column'] = $fieldDesc['name'];
                if (!isset($fieldDesc['params']))
                    $fieldDesc['params'] = array();

                $prop = $properties->addChild('property');

                $prop->addAttribute('id', $fieldDesc['name']);
                $prop->addAttribute('type', $fieldDesc['type']);
                $transform = $prop->addChild('transform');
                $logic = $transform->addChild('logic');
                $logic->addAttribute('source', $data['source']);
                $logic->addAttribute('type', $fieldDesc['logical_type']);
                if (in_array($fieldDesc['type'], array('title', 'body', 'text'))) {
                    foreach ($this->getShopLocales($id) as $lang) {
                        $field = $logic->addChild('field');
                        $field->addAttribute('language', $lang);
                        $field->addAttribute('column', $fieldDesc['column'] . '_' . $lang);
                    }
                } else {
                    $field = $logic->addChild('field');
                    $field->addAttribute('column', $fieldDesc['column']);
                }
                $params = $prop->addChild('params');
                foreach($fieldDesc['params'] as $paramKey => $paramData) {
                    $param = $params->addChild($paramKey);
                    foreach($paramData as $k => $v)
                        $param->addAttribute($k, $v);
                }
            }
        }

        return $this->xml->asXML();
    }
}