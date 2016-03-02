{if $sArticle.additional_details.sConfigurator}
    {$detailLink={url controller=detail sArticle=$sArticle.articleID number=$sArticle.ordernumber}}
{else}
    {$detailLink=$sArticle.linkDetails}
{/if}

<div class="cart--item">
    {* Article image *}
    {block name='frontend_checkout_ajax_cart_articleimage'}
        <div class="thumbnail--container">
            {* Real product *}
            {block name='frontend_checkout_ajax_cart_articleimage_product'}
                {if $sArticle.image.thumbnails[0].source}
                    <img src="{$sArticle.image.thumbnails[0].source}" alt="{$desc}" title="{$desc|truncate:25:""}" class="thumbnail--image" />
                {/if}
            {/block}
        </div>
    {/block}

    {* Article actions *}
    {block name='frontend_checkout_ajax_cart_add'}
        <div>
            <form name="sAddToBasket{$sArticle.ordernumber}" method="post" class="bx-add-to-cart" data-add-article="true" data-eventName="submit" {if $theme.offcanvasCart} data-showModal="false" data-addArticleUrl="{url controller=checkout action=ajaxAddArticleCart}"{/if}>
                <input type="hidden" name="sAdd" value="{$sArticle.ordernumber}"/>
                {if (!isset($sArticle.active) || $sArticle.active)}
                    {if $sArticle.isAvailable}
                        {block name="frontend_detail_buy_button_container"}
                            {block name="frontend_detail_buy_button"}
                                    {if $sArticle.price > $BasketrulesConfig->productPriceLimitToAddToBasket}
                                         <button class="btn is--icon-left is--large action--add" name="{s name="DetailBuyActionAdd"}{/s}"{if $buy_box_display} style="{$buy_box_display}"{/if}>
                                            <i class="icon--basket"></i>
                                         </button>
                                    {/if}
                            {/block}
                        {/block}
                    {/if}
                {/if}
            </form>
        </div>
    {/block}

    {* Article name *}
    {block name='frontend_checkout_ajax_cart_articlename'}
        <a class="item--link" href="{$detailLink}" title="{$sArticle.articlename|escape}">
            {block name="frontend_checkout_ajax_cart_articlename_name"}
                <span class="item--name">
                    {if $theme.offcanvasCart}
                        {$sArticle.articleName}
                    {else}
                        {$sArticle.articleName|truncate:28:"...":true}
                    {/if}
                </span>
            {/block}
            {block name="frontend_checkout_ajax_cart_articlename_price"}
                <span class="item--price">{$sArticle.price|currency}</span>
            {/block}
        </a>
    {/block}
</div>