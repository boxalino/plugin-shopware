{extends file="parent:frontend/checkout/ajax_cart.tpl"}

{block name='frontend_checkout_ajax_cart_button_container_inner' append}
    {if $sRecommendations}
        {foreach $sRecommendations as $sArticleSub}
            {include file="frontend/checkout/article_compact.tpl" sArticle=$sArticleSub}
        {/foreach}
    {/if}
{/block}
