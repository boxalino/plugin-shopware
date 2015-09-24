<?php


class Shopware_Plugins_Frontend_Boxalino_P13NHelper
{

    private static $instance = null;

    /**
     * @var Enlight_Controller_Request_Request
     */
    private $request;

    private function __construct()
    {
    }

    /**
     * @return null|Shopware_Plugins_Frontend_Boxalino_P13NHelper
     */
    public static function instance() {
        if (self::$instance == null)
            self::$instance = new Shopware_Plugins_Frontend_Boxalino_P13NHelper();
        return self::$instance;
    }

    /**
     * @return string
     */
    private function getShortLocale() {
        $locale = Shopware()->Shop()->getLocale();
        $shortLocale = $locale->getLocale();
        $position = strpos($shortLocale, '_');
        if ($position !== false)
            $shortLocale = substr($shortLocale, 0, $position);
        return $shortLocale;
    }

    private function getSearchLimit() {
        return Shopware()->Config()->get('maxlivesearchresults', 6);
    }

    private function debug($request, $response = null) {
        if ($this->isDebug()) {
            echo '<pre>';
            var_dump($request, $response);
            echo '</pre>';
        }
    }

    private function isDebug() {
        return $this->Request()->getQuery('dev_bx_disp', false) == 'true';
    }

    public function quickSearch($text)
    {
        return $this->search($text, 0, $this->getSearchLimit());
    }

    public function search($text, $p13nOffset, $p13nHitCount, $options = array())
    {
        $p13nChoiceId = Shopware()->Config()->get('boxalino_search_widget_name');
        $p13nHost = Shopware()->Config()->get('boxalino_host');
        //$p13nAccount = Shopware()->Config()->get('boxalino_account');
        $p13nAccount = $this->getAccount();
        $p13nUsername = Shopware()->Config()->get('boxalino_username');
        $p13nPassword = Shopware()->Config()->get('boxalino_password');
        $cookieDomain = Shopware()->Config()->get('boxalino_domain');

        $p13nSearch = $text;
        $p13nLanguage = $this->getShortLocale();
        $p13nFields = array('id', 'title', 'body', 'mainnumber', 'name', 'net_price', 'standardPrice',
            'products_mediaId',
            'products_supplier',
            'products_net_price',
            'products_tax',
            'products_group_id'
        ); // fields you want in the response, i.e. title, body, etc.


// Create basic P13n client
        $p13n = new HttpP13n();
        $p13n->setHost($p13nHost);
        $p13n->setAuthorization($p13nUsername, $p13nPassword);

// Create main choice request object
        $choiceRequest = $p13n->getChoiceRequest($p13nAccount, $cookieDomain);

// Setup main choice inquiry object
        $inquiry = new \com\boxalino\p13n\api\thrift\ChoiceInquiry();
        $inquiry->choiceId = $p13nChoiceId;

// Setup a search query
        $searchQuery = new \com\boxalino\p13n\api\thrift\SimpleSearchQuery();
        $searchQuery->indexId = $p13nAccount;
        $searchQuery->language = $p13nLanguage;
        $searchQuery->returnFields = $p13nFields;
        $searchQuery->offset = $p13nOffset;
        $searchQuery->hitCount = $p13nHitCount;
        $searchQuery->queryText = $p13nSearch;

        if (!empty($options)) {
            $searchQuery->facetRequests = [];
            foreach ($options as $field => $values) {
                switch ($field) {
                    case 'categoryName':
                    case 'sort':
                        continue 2;
                    case 'category':
                        $searchQuery->facetRequests[] = new \com\boxalino\p13n\api\thrift\FacetRequest([
                            'fieldName' => 'categories',
                            'selectedValues' => [new \com\boxalino\p13n\api\thrift\FacetValue([
                                'hierarchyId' => $values,
                                'hierarchy' => $options['categoryName']
                            ])]
                        ]);
                        break;
                    case 'price':
                        $searchQuery->facetRequests[] = new \com\boxalino\p13n\api\thrift\FacetRequest([
                            'fieldName' => 'discountedPrice',
                            'numerical' => true,
                            'range' => true,
                            'selectedValues' => [new \com\boxalino\p13n\api\thrift\FacetValue([
                                'rangeFromInclusive' => $values['start'],
                                'rangeToExclusive' => $values['end']
                            ])]
                        ]);
                        break;
                    default:
                        $selectedValues = [];
                        foreach ($values as $value) {
                            $selectedValues[] = new \com\boxalino\p13n\api\thrift\FacetValue([
                                'stringValue' => $value
                            ]);
                        }
                        $searchQuery->facetRequests[] = new \com\boxalino\p13n\api\thrift\FacetRequest([
                            'fieldName' => "products_$field",
                            'selectedValues' => $selectedValues
                        ]);
                }
            }

            $sortFields = [];
            if (!empty($options['sort'])) {
                $sortFields[] = new \com\boxalino\p13n\api\thrift\SortField(array(
                    'fieldName' => $options['sort']['field'],
                    'reverse' => $options['sort']['reverse']
                ));
            }

            if (!empty($sortFields))
                $searchQuery->sortFields = $sortFields;
        }

        // Connect search query to the inquiry
        $inquiry->simpleSearchQuery = $searchQuery;

        // Add inquiry to choice request
        $choiceRequest->inquiries = array($inquiry);

        // Call the service
        try {
            $choiceResponse = $p13n->choose($choiceRequest);
            $this->debug($choiceRequest, $choiceResponse);
        } catch (Exception $e) {
            $this->debug($choiceRequest, $e);
            if ($this->isDebug()) {
                exit;
            }
            throw $e;
        }
        return $choiceResponse;
    }

    public function autocomplete($text, $p13nOffset, $p13nHitCount)
    {
       $p13nChoiceId = Shopware()->Config()->get('boxalino_autocomplete_widget_name');
        // $p13nChoiceId = 'autocomplete';
        $p13nHost = Shopware()->Config()->get('boxalino_host');
        //$p13nAccount = Shopware()->Config()->get('boxalino_account');
        $p13nAccount = $this->getAccount();
        $p13nUsername = Shopware()->Config()->get('boxalino_username');
        $p13nPassword = Shopware()->Config()->get('boxalino_password');
        $cookieDomain = Shopware()->Config()->get('boxalino_domain');

        $p13nSearch = $text;
        $p13nLanguage = $this->getShortLocale();
        $p13nFields = array('id', 'title', 'body', 'mainnumber', 'name', 'net_price', 'standardPrice',
            'products_mediaId',
            'products_supplier',
            'products_net_price',
            'products_tax',
            'products_group_id'
        );


// Create basic P13n client
        $p13n = new HttpP13n();
        $p13n->setHost($p13nHost);
        $p13n->setAuthorization($p13nUsername, $p13nPassword);

// Create main choice request object
        $autocompleteRequest = $p13n->getAutocompleteRequest($p13nAccount, $cookieDomain);

// Setup a search query
        $searchQuery = new \com\boxalino\p13n\api\thrift\SimpleSearchQuery();
        $searchQuery->indexId = $p13nAccount;
        $searchQuery->language = $p13nLanguage;
        $searchQuery->returnFields = $p13nFields;
        $searchQuery->offset = $p13nOffset;
        $searchQuery->hitCount = $p13nHitCount;
        $searchQuery->queryText = $p13nSearch;

        $autocompleteQuery = new \com\boxalino\p13n\api\thrift\AutocompleteQuery();
        $autocompleteQuery->indexId = $p13nAccount;
        $autocompleteQuery->language = $this->getShortLocale();
        $autocompleteQuery->queryText = $p13nSearch;

// Add inquiry to choice request
        $autocompleteRequest->choiceId = $p13nChoiceId;
        $autocompleteRequest->autocompleteQuery = $autocompleteQuery;
        $autocompleteRequest->searchChoiceId = $p13nChoiceId;
        $autocompleteRequest->searchQuery = $searchQuery;

// Call the service
        try {
            $choiceResponse = $p13n->autocomplete($autocompleteRequest);
            $this->debug($autocompleteRequest, $choiceResponse);
        } catch (Exception $e) {
            $this->debug($autocompleteRequest, $e);
            if ($this->isDebug()) {
                exit;
            }
            throw $e;
        }
        return $choiceResponse;
    }

    public function findRawRecommendations($id, $role, $p13nChoiceId, $count = 5, $fieldName = 'products_group_id')
    {
        $p13nHost = Shopware()->Config()->get('boxalino_host');
        //$p13nAccount = Shopware()->Config()->get('boxalino_account');
        $p13nAccount = $this->getAccount();
        $p13nUsername = Shopware()->Config()->get('boxalino_username');
        $p13nPassword = Shopware()->Config()->get('boxalino_password');
        $cookieDomain = Shopware()->Config()->get('boxalino_domain');

        $p13nLanguage = 'de'; // or de, fr, it, etc.
        $p13nFields = array('id', 'products_group_id');

// Create basic P13n client
        $p13n = new HttpP13n();
        $p13n->setHost($p13nHost);
        $p13n->setAuthorization($p13nUsername, $p13nPassword);

// Create main choice request object
        $choiceRequest = $p13n->getChoiceRequest($p13nAccount, $cookieDomain);

// Setup main choice inquiry object
        $inquiry = new \com\boxalino\p13n\api\thrift\ChoiceInquiry();
        $inquiry->choiceId = $p13nChoiceId;

// Setup a search query
        $searchQuery = new \com\boxalino\p13n\api\thrift\SimpleSearchQuery();
        $searchQuery->indexId = $p13nAccount;
        $searchQuery->language = $p13nLanguage;
        $searchQuery->returnFields = $p13nFields;
        $searchQuery->offset = 0;
        $searchQuery->hitCount = $count;

// Connect search query to the inquiry
        $inquiry->simpleSearchQuery = $searchQuery;
        $inquiry->contextItems = array(
            new \com\boxalino\p13n\api\thrift\ContextItem(array(
                //'indexId' => Shopware()->Config()->get('boxalino_account'),
                'indexId' => $this->getAccount(),
                'fieldName' => $fieldName,
                'contextItemId' => $id,
                'role' => $role
            ))
        );

// Add inquiry to choice request
        $choiceRequest->inquiries = array($inquiry);

// Call the service
        try {
            $choiceResponse = $p13n->choose($choiceRequest);
            $this->debug($choiceRequest, $choiceResponse);
        } catch (Exception $e) {
            $this->debug($choiceRequest, $e);
            if ($this->isDebug()) {
                exit;
            }
            throw $e;
        }
        return $choiceResponse;
    }

    public function findRecommendations($id, $role, $p13nChoiceId, $count = 5, $fieldName = 'products_group_id') {
        return $this->getLocalArticles($this->extractResults($this->findRawRecommendations($id, $role, $p13nChoiceId, $count, $fieldName)));
    }

    public function extractResults($choiceResponse)
    {
        $results = array();
        $count = 0;
        /** @var \com\boxalino\p13n\api\thrift\Variant $variant */
        foreach ($choiceResponse->variants as $variant) {
            /** @var \com\boxalino\p13n\api\thrift\SearchResult $searchResult */
            $searchResult = $variant->searchResult;
            $count += $searchResult->totalHitCount;
            foreach ($searchResult->hits as $item) {
                $result = array();
                foreach ($item->values as $key => $value) {
                    if (is_array($value) && count($value) == 1) {
                        $result[$key] = array_shift($value);
                    } else {
                        $result[$key] = $value;
                    }
                }
                // Widget's meta data, mostly used for event tracking
                $result['_widgetTitle'] = $variant->searchResultTitle;
                $results[] = $result;
            }
        }
        return array('results' => $results, 'count' => $count);
    }


    /**
     * @param $choiceResponse
     * @param $facet
     * @return com\boxalino\p13n\api\thrift\FacetValue[]
     */
    public function extractFacet($choiceResponse, $facet)
    {
        $facets = array();
        /** @var \com\boxalino\p13n\api\thrift\Variant $variant */
        foreach ($choiceResponse->variants as $variant) {
            foreach ($variant->searchResult->facetResponses as $facetResponse) {
                if ($facetResponse->fieldName == $facet)
                    $facets = array_merge($facets, $facetResponse->values);
            }
        }
        return $facets;
    }

    public function getLocalArticles($results)
    {
        $articles = array();
        foreach ($results['results'] as $p13nResult) {
            $id = intval($p13nResult['products_group_id']);
            $articleNew = Shopware()->Modules()->Articles()->sGetPromotionById('fix', 0, $id);
            if (!empty($articleNew['articleID'])) {
                $articles[] = $articleNew;
            }
        }
        return $articles;
    }

    /**
     * Sets request instance
     *
     * @param Enlight_Controller_Request_Request $request
     */
    public function setRequest(Enlight_Controller_Request_Request $request)
    {
        $this->request = $request;
    }

    /**
     * Returns request instance
     *
     * @return Enlight_Controller_Request_Request
     */
    public function Request()
    {
        return $this->request;
    }

    public static function getAccount(){

        if(
            Shopware()->Config()->get('boxalino_dev', 0) == 1
        ){
            return Shopware()->Config()->get('boxalino_account') . '_dev';
        } else{
            return Shopware()->Config()->get('boxalino_account');
        }

    }
}