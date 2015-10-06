<?php

require_once __DIR__ . '/lib/vendor/Thrift/ClassLoader/ThriftClassLoader.php';
require_once __DIR__ . '/lib/vendor/Thrift/HttpP13n.php';

class Shopware_Plugins_Frontend_Boxalino_P13NHelper
{

    private static $instance = null;

    /**
     * @var Enlight_Controller_Request_Request
     */
    private $request;

    private $config;

    private $relaxationEnabled = false;

    private function __construct()
    {
        $this->config = Shopware()->Config();
    }

    /**
     * @return null|Shopware_Plugins_Frontend_Boxalino_P13NHelper
     */
    public static function instance() {
        if (self::$instance == null)
            self::$instance = new Shopware_Plugins_Frontend_Boxalino_P13NHelper();
        return self::$instance;
    }

    public function search($text, $p13nOffset, $p13nHitCount, $options = array())
    {
        $p13nHost = $this->config->get('boxalino_host');
        $p13nAccount = $this->getAccount();
        $p13nUsername = $this->config->get('boxalino_username');
        $p13nPassword = $this->config->get('boxalino_password');
        $cookieDomain = $this->config->get('boxalino_domain');

        $p13nSearch = $text;
        $p13nLanguage = $this->getShortLocale();
        // fields you want in the response, i.e. title, body, etc.
        $p13nFields = array(
            'id',
            'title',
            'body',
            'name',
            'products_group_id',
            'net_price',
            'standardPrice',
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
        $choiceRequest = $p13n->getChoiceRequest($p13nAccount, $cookieDomain);

        // Setup main choice inquiry object
        $inquiry = new \com\boxalino\p13n\api\thrift\ChoiceInquiry();
        $inquiry->choiceId = $this->config->get('boxalino_search_widget_name');

        // enable relaxation
        if (
            ($this->config->get('boxalino_search_suggestions_amount') > 0 &&
            ($this->config->get('boxalino_search_suggestions_minimum') > 0 ||
            $this->config->get('boxalino_search_suggestions_maximum') > 0)) ||
            ($this->config->get('boxalino_search_subphrase_amount') > 0 &&
            $this->config->get('boxalino_search_subphrase_minimum') > 0)
        ) {
            $inquiry->withRelaxation = $this->relaxationEnabled = true;
        }

        // Setup a search query
        $searchQuery = new \com\boxalino\p13n\api\thrift\SimpleSearchQuery();
        $searchQuery->indexId = $p13nAccount;
        $searchQuery->language = $p13nLanguage;
        $searchQuery->returnFields = $p13nFields;
        $searchQuery->offset = $p13nOffset;
        $searchQuery->hitCount = $p13nHitCount;
        $searchQuery->queryText = $p13nSearch;
        $searchQuery->groupBy = 'products_group_id';

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
            $this->debug($choiceRequest, $e->getMessage());
            if ($this->isDebug()) {
                exit;
            }
            Shopware()->PluginLogger()->debug('Boxalino Search: Error occurred with message ' . $e->getMessage());
            return;
        }
        return $choiceResponse;
    }

    public function autocomplete($text, $p13nOffset, $p13nHitCount)
    {
        $p13nHost = $this->config->get('boxalino_host');
        $p13nAccount = $this->getAccount();
        $p13nUsername = $this->config->get('boxalino_username');
        $p13nPassword = $this->config->get('boxalino_password');
        $cookieDomain = $this->config->get('boxalino_domain');

        $p13nSearch = $text;
        $p13nLanguage = $this->getShortLocale();
        $p13nFields = array('products_ordernumber');


        // Create basic P13n client
        $p13n = new HttpP13n();
        $p13n->setHost($p13nHost);
        $p13n->setAuthorization($p13nUsername, $p13nPassword);

        // Create main choice request object
        $choiceRequest = $p13n->getChoiceRequest($p13nAccount, $cookieDomain);
        $autocompleteRequest = new \com\boxalino\p13n\api\thrift\AutocompleteRequest();

        // Setup a search query
        $searchQuery = new \com\boxalino\p13n\api\thrift\SimpleSearchQuery();
        $searchQuery->indexId = $p13nAccount;
        $searchQuery->language = $p13nLanguage;
        $searchQuery->returnFields = $p13nFields;
        $searchQuery->offset = $p13nOffset;
        $searchQuery->hitCount = $p13nHitCount;
        $searchQuery->queryText = $p13nSearch;
        $searchQuery->groupBy = 'products_group_id';

        $autocompleteQuery = new \com\boxalino\p13n\api\thrift\AutocompleteQuery();
        $autocompleteQuery->indexId = $p13nAccount;
        $autocompleteQuery->language = $this->getShortLocale();
        $autocompleteQuery->queryText = $p13nSearch;
        $autocompleteQuery->suggestionsHitCount = $p13nHitCount;
        $autocompleteQuery->highlight = true;
        $autocompleteQuery->highlightPre = '<em>';
        $autocompleteQuery->highlightPost = '</em>';

        // Add inquiry to choice request
        $cemv = Shopware()->Front()->Request()->getCookie('cemv');
        if(empty($cemv)) {
            $cemv = self::getSessionId();
        }
        $autocompleteRequest->userRecord = $choiceRequest->userRecord;
        $autocompleteRequest->choiceId = $this->config->get('boxalino_autocomplete_widget_name');
        $autocompleteRequest->profileId = $cemv;
        $autocompleteRequest->autocompleteQuery = $autocompleteQuery;
        $autocompleteRequest->searchChoiceId = $this->config->get('boxalino_search_widget_name');
        $autocompleteRequest->searchQuery = $searchQuery;

        // Call the service
        try {
            $choiceResponse = $p13n->autocomplete($autocompleteRequest);
            $this->debug($autocompleteRequest, $choiceResponse);
        } catch (Exception $e) {
            $this->debug($autocompleteRequest, $e->getMessage());
            if ($this->isDebug()) {
                exit;
            }
            Shopware()->PluginLogger()->debug('Boxalino Autocompletion: Error occurred with message ' . $e->getMessage());
            return;
        }
        return $choiceResponse;
    }

    public function findRawRecommendations($id, $role, $p13nChoiceId, $count = 5, $offset = 0, $fieldName = 'products_group_id', $context = array())
    {
        $p13nHost = $this->config->get('boxalino_host');
        $p13nAccount = $this->getAccount();
        $p13nUsername = $this->config->get('boxalino_username');
        $p13nPassword = $this->config->get('boxalino_password');
        $cookieDomain = $this->config->get('boxalino_domain');

        $p13nLanguage = $this->getShortLocale();
        $p13nFields = array('id', 'products_group_id');

        // Create basic P13n client
        $p13n = new HttpP13n();
        $p13n->setHost($p13nHost);
        $p13n->setAuthorization($p13nUsername, $p13nPassword);

        // Create main choice request object
        $choiceRequest = $p13n->getChoiceRequest($p13nAccount, $cookieDomain);
        $choiceRequest->inquiries = array();

        // Add context parameters if given
        if (count($context)) {
            foreach($context as $key => $value) {
                if (!is_array($value)) {
                    $context[$key] = array($value);
                }
            }
            $requestContext = new \com\boxalino\p13n\api\thrift\RequestContext();
            $requestContext->parameters = $context;
            $choiceRequest->requestContext = $requestContext;
        }

        // Setup a context item
        if (!empty($id)) {
            $contextItems = array(
                new \com\boxalino\p13n\api\thrift\ContextItem(array(
                    'indexId' => $p13nAccount,
                    'fieldName' => $fieldName,
                    'contextItemId' => $id,
                    'role' => $role
                ))
            );
        }

        // Setup a search query
        $searchQuery = new \com\boxalino\p13n\api\thrift\SimpleSearchQuery();
        $searchQuery->indexId = $p13nAccount;
        $searchQuery->language = $p13nLanguage;
        $searchQuery->returnFields = $p13nFields;
        $searchQuery->offset = $offset;
        $searchQuery->hitCount = $count;
        $searchQuery->groupBy = 'products_group_id';

        if (!is_array($p13nChoiceId)) {
            $p13nChoiceId = array($p13nChoiceId);
        }
        foreach ($p13nChoiceId as $choiceId) {
            // Setup main choice inquiry object
            $inquiry = new \com\boxalino\p13n\api\thrift\ChoiceInquiry();
            $inquiry->choiceId = $choiceId;
            $inquiry->minHitCount = $count;

            // Connect search query to the inquiry
            $inquiry->simpleSearchQuery = $searchQuery;
            if (!empty($id))$inquiry->contextItems = $contextItems;

            // Add inquiry to choice request
            $choiceRequest->inquiries[] = $inquiry;
        }

        // Call the service
        try {
            $choiceResponse = $p13n->choose($choiceRequest);
            $this->debug($choiceRequest, $choiceResponse);
        } catch (Exception $e) {
            $this->debug($choiceRequest, $e->getMessage());
            if ($this->isDebug()) {
                exit;
            }
            Shopware()->PluginLogger()->debug('Boxalino Recommendation: Error occurred with message ' . $e->getMessage());
            return;
        }
        return $choiceResponse;
    }

    public function findRecommendations($id, $role, $p13nChoiceId, $count = 5, $offset = 0, $context = array(), $fieldName = 'products_group_id') {
        $results = $this->extractResults($this->findRawRecommendations($id, $role, $p13nChoiceId, $count, $fieldName, $context), $p13nChoiceId);
        if (is_array($p13nChoiceId)) {
            $articleResults = array();
            foreach ($results as $key => $result) {
                $articleResults[$key] = $this->getLocalArticles($result);
            }
            return $articleResults;
        } else {
            return $this->getLocalArticles($results);
        }
    }

    public function extractResults($choiceResponse, $choiceIds = array())
    {
        $results = array();
        $count = 0;
        $choiceIdCount = is_array($choiceIds) ? count($choiceIds) : 0;
        /** @var \com\boxalino\p13n\api\thrift\Variant $variant */
        foreach ($choiceResponse->variants as $variant) {
            /** @var \com\boxalino\p13n\api\thrift\SearchResult $searchResult */
            $searchResult = $variant->searchResult;
            if ($choiceIdCount) {
                list($configOption, $choiceId) = each($choiceIds);
                $results[$configOption] = array(
                    'results' => $this->extractResultsFromHitGroups($searchResult->hitsGroups),
                    'count' => $searchResult->totalHitCount
                );
            } else {
                $count += $searchResult->totalHitCount;
                $this->extractResultsFromHitGroups($searchResult->hitsGroups, $results);
            }
        }
        if ($choiceIdCount) {
            return $results;
        } else {
            return array('results' => $results, 'count' => $count);
        }
    }

    public function extractResultsFromHitGroups($hitsGroups, &$results = array())
    {
        /** @var \com\boxalino\p13n\api\thrift\HitsGroup $group */
        foreach ($hitsGroups as $group) {
            /** @var \com\boxalino\p13n\api\thrift\Hit $item */
            foreach ($group->hits as $item) {
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
        return $results;
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

    public function getRelaxationSuggestions(\com\boxalino\p13n\api\thrift\ChoiceResponse $response)
    {
        $suggestions = array();
        if ($this->relaxationEnabled) {
            /** @var \com\boxalino\p13n\api\thrift\Variant $variant */
            foreach ($response->variants as $variant) {
                if (is_object($variant->searchRelaxation)) {
                    /** @var \com\boxalino\p13n\api\thrift\SearchResult $searchResult */
                    foreach ($variant->searchRelaxation->suggestionsResults as $searchResult) {
                        $suggestions[] = array(
                            'text' => $searchResult->queryText,
                            'count' => $searchResult->totalHitCount,
                            'results' => $this->extractResultsFromHitGroups($searchResult->hitsGroups),
                        );
                    }
                }
            }
        }
        return $suggestions;
    }

    public function getRelaxationSubphraseResults(\com\boxalino\p13n\api\thrift\ChoiceResponse $response)
    {
        $subphrases = array();
        if ($this->relaxationEnabled) {
            /** @var \com\boxalino\p13n\api\thrift\Variant $variant */
            foreach ($response->variants as $variant) {
                if (is_object($variant->searchRelaxation)) {
                    /** @var \com\boxalino\p13n\api\thrift\SearchResult $searchResult */
                    foreach ($variant->searchRelaxation->subphrasesResults as $searchResult) {
                        $subphrases[] = array(
                            'text' => $searchResult->queryText,
                            'count' => $searchResult->totalHitCount,
                            'results' => $this->extractResultsFromHitGroups($searchResult->hitsGroups),
                        );
                    }
                }
            }
        }
        return $subphrases;
    }

    public function getAutocompleteSuggestions(\com\boxalino\p13n\api\thrift\AutocompleteResponse $response)
    {
        $suggestions = array();
        foreach ($response->hits as $hit) {
            $suggestions[] = array(
                'text' => $hit->suggestion,
                'html' => (strlen($hit->highlighted) ? $hit->highlighted : $hit->suggestion),
                'hits' => $hit->searchResult->totalHitCount,
            );
        }
        return $suggestions;
    }

    public function getAutocompletePreviewsearch(\com\boxalino\p13n\api\thrift\AutocompleteResponse $response)
    {
        $results = array();
        foreach ($this->extractResultsFromHitGroups($response->prefixSearchResult->hitsGroups) as $result) {
            $results[] = $result['products_ordernumber'];
        }
        return $results;
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

    /**
     * @return string
     */
    private function getShortLocale()
    {
        $locale = Shopware()->Shop()->getLocale();
        $shortLocale = $locale->getLocale();
        $position = strpos($shortLocale, '_');
        if ($position !== false)
            $shortLocale = substr($shortLocale, 0, $position);
        return $shortLocale;
    }

    public function getSearchLimit()
    {
        return $this->config->get('maxlivesearchresults', 6);
    }

    private function debug($request, $response = null)
    {
        if ($this->isDebug()) {
            echo '<pre>';
            var_dump($request, $response);
            echo '</pre>';
        }
    }

    private function isDebug()
    {
        return $this->Request()->getQuery('dev_bx_disp', false) == 'true';
    }

    public static function getAccount()
    {
        $config = Shopware()->Config();
        if(
            $config->get('boxalino_dev', 0) == 1
        ){
            return $config->get('boxalino_account') . '_dev';
        } else{
            return $config->get('boxalino_account');
        }

    }
}