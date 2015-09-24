1. # Installation
 1. ## Download and *unzip* the archive. {color:grey}(it can be downloaded from github for example){color}
 1. ## Go to the directory you just unzipped the plugin into, and *copy the _Frontend_ folder into the _Local_ directory* of your Shopware installation ("Shopware Directory"\engine\Shopware\Plugins\Local)
 1. ## Set chmod for *Boxalino directory* on 777 recursively (example in Linux: _chmod -R 777 Boxalino_)
 1. ## In your administration backend *install the Boxalino plugin* (Configuration > Plugin Manager > Community extensions). A configuration will appear where you can insert your private and public keys and modify other information. The plugin will not work untill you save the settings. Later the configuration will be available under Configuration > Basic Settings > Additional Settings > Boxalino.

## If anything is changed in Boxalino plugin or Boxaline Service then you need clear the cache (http://wiki.shopware.de/Cache_detail_855.html#Clear_cache_from_the_menu).

## You can manually export data from *Content > Import / export > Boxalino Export*. You'll have to refresh the page to see this new menu entry.
By default a daily export is scheduled.
# h1. Manual export
## Navigate to *Content > Import / export > Boxalino Export*.
## Choose either *Full export* or *Delta export*. Full export exports all data to the server. Delta export exports only data modified later then last export was made.
## Exporting can take some time. Please *wait for a popup window* with results. {color:red}Some browsers may try to block it from appearing.{color}
## After the export you may also be required to take some actions in the *Boxalino's administration panel*.
# h1. Adding Recommendations
## h2. General instructions
To add/substitute another recommendation widget:
### Add new widget in the Shopware frontend or find which one you wish to modify. You will need name of controller and action which show this widget on the webpage as well as variable which stores list of shown items.
### Use existing classes *Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor* and *Shopware_Plugins_Frontend_Boxalino_WidgetInterceptor* to intercept the page before it is fully rendered. There you can modify displayed data. Choosing which class to use depends on namespace of intercepted action (frontend or widget).
Method Shopware_Plugins_Frontend_Boxalino_P13NHelper::findRecommendations returns array of items found in p13n. You can bind them in the interceptor to view variables used in you recommendation widget like this:
$view->items = Shopware_Plugins_Frontend_Boxalino_P13NHelper::instance()->findRecommendations(articleID, role, boxalino_widget_id, results_count).
### In Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor you can also see event reporting. In case you want to extend reporting this is the place where you can do it.
## h2. Case study of replacing existing element - changing _similar items_ section in item detail page
In Shopware, I have a page displaying item details. Next to item's description there's is a small box showing similar items which I may be interested in. I want those items to be suggested by Boxalino because it knows better.
### h3. Finding the correct spot
Firstly, I need to find out where the displayed items actually come from. This is not hard but, unfortunately, it may take some time of tedious file navigation. Let's begin.
#### h4. Action and controller
Firstly, I need to find out which controller and action displays this page. The information can be retrieved from request parameters. The easiest way to peek at this data is to actually print it on screen. To do that you can use existing code in /engine/Shopware/Plugins/Local/Frontend/Boxalino/Bootstrap.php. There are to methods: _onFrontend_ and _onWidget_. If I uncomment the following lines in _onFrontend_:
{code}
$controller = $arguments->getSubject();
$request = $controller->Request();
var_dump($request->getParams());
{code}
after refreshing, I can see on the bottom of item detail page this:
{code}
array (size=4)
  'rewriteUrl' => string '1' (length=1)
  'sViewport' => string 'detail' (length=6)
  'sArticle' => string '19' (length=2)
  'controller' => string 'detail' (length=6)
{code}
{panel:title=AJAX may complicate things|titleBGColor=#F7D6C1|bgColor=#FFFFCE}
Sometimes you may be replacing content returned by AJAX. The _var_dump_ is still useful for getting controller name, however the output may be visible only in page inspector (or Firebug). You may have to check "Network" section and look at the response. It may also include some HTML formatting making it harder to read but the date should be there.
{panel}
This output tells me that the page was rendered by controller _detail_. The action is not specified so it must have been _index_. And because this came from _onFrontend_ I know, that the package I need to look into is called _Frontend_. I also note that _sArticle_ contains the id of displayed item.
This leads me to file _/engine/Shopware/Controllers/Frontend/Detail.php_ with class _Shopware_Controllers_Frontend_Detail_ and method _indexAction_. I should now comment back lines which gave me this information.
#### h4. Template
I now know which action displays the page: _Shopware_Controllers_Frontend_Detail::indexAction_. This means that (unless something else is explicitly stated in the code, usually _View()->loadTemplate_) the template showing this page is _/templates/_default/frontend/detail/index.tpl_. (parts of the path correspond to the controller and action). In there I finally find the following lines
{code:html}
{* Related articles *}
{block name="frontend_detail_index_tabs_related"}
    {include file="frontend/detail/related.tpl"}
{/block}
{code}
which are responsible for displaying the component I want to modify. I'm almost there.
#### h4. Variables
Finally, in file _/templates/_default/frontend/detail/related.tpl_ is the line I'm looking for:
{code}
{foreach from=$sArticle.sRelatedArticles item=sArticleSub key=key name="counter"}
{code}
This is the actual loop that shows similar items.
I can see that it works on property _sRelatedArticles_ of object _sArticle_. This is the item and property I will have to modify.
It's high time I commented out the code from first point.
{panel:title=Difficulties while browsing the code|titleBGColor=#F7D6C1|bgColor=#FFFFCE}
Unfortunately, you may often have to guess which variable is responsible for what. Sometimes, there's more then one object that needs to be modified. For example, both _$sCrossSimilarShown_ and _$sCrossBoughtToo_ are responsible for showing similar items when adding to cart.
{panel}
### h3. Intercepting and feeding data
I remember I was using method _onFrontend_ to get controller and action. Now this method contains only call to _Shopware_Plugins_Frontend_Boxalino_FrontendInterceptor::intercept_. I'm going to use this method to actually insert needed data into the view.
And just to collect all the information gathered up to this point in one place:
|| Package || Controller || Action || Parameter with item ID || View object || Property ||
| Frontend | Details | empty (defaults to _index_) | sArticle | sArticle | sRelatedArticles |
#### h4. Intercepting correct request
In this method I have available two variables _$controllerName_ and _$actionName_. I have to check their values to intercept the wanted request. Controller was called _detail_ and action was empty (default, meaning _index_) so my code for intercepting this and only this request is as follows:
{code}
if ($controllerName == 'detail') {
    if (empty($actionName)) {
        // Here I will replace view data
    }
}
{code}
#### h4. Replacing data
Firstly, I have to get the object _sArticle_ which is used in the template. I have variable _$view_ to help me with that:
{code}
$sArticle = $view->sArticle;
{code}
Then, I need to get replacement data. New items can be requested like this:
{code}
$term = trim(strip_tags(htmlspecialchars_decode(stripslashes($request->sArticle))));
$similarArticles = $this->helper->findRecommendations(
    $term,
    'mainProduct',
    Shopware()->Config()->get('boxalino_search_widget_name')
);
{code}
I need to find out what item I was looking at -- this information is stored in _$request->sArticle_. The stripped data is passed to p13n by calling _$this->helper->findRecommendations_. For arguments it takes: _id of main object_, _role_ and _p13n widget id_. This function requests information from p13n and then translates it into "native" shopware objects. Usually there's no need for further modification of the data.
#### h4. Putting it back in the view
The final step is to put the new data back in the view. Firstly, I have to update the object _$sArticle_ and then re-assign it in the view:
{code}
$sArticle['sSimilarArticles'] = $similarArticles;
$view->assign('sArticle', $sArticle);
{code}
After this new data should be displayed on the page.