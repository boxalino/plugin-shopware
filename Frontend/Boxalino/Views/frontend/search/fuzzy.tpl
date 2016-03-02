{extends file="parent:frontend/search/fuzzy.tpl"}

{block name='frontend_index_content'}
    {if $bxHasOtherItemTypes}
        <div class="tab-menu--search">
            <div class="tab--navigation">
                <a href="#" title="{s name='bx_search_tab_articles'}Artikel{/s}" class="tab--link">
                    <h2>{s name='bx_search_tab_articles'}Artikel{/s}</h2>
                    <span class="product--rating-count">{$sSearchResults.sArticlesCount}</span>
                </a>
                {if $sBlogArticles}
                    <a href="#" title="{s name='bx_search_tab_blogs'}Blog-Beitr&auml;ge{/s}" class="tab--link tab--blog{if $bxActiveTab == 'blog'} is--active{/if}">
                        <h2>{s name='bx_search_tab_blogs'}Blog-Beitr&auml;ge{/s}</h2>
                        <span class="product--rating-count">{$bxBlogCount}</span>
                    </a>
                {/if}
            </div>
            <div class="tab--container-list">
                <div class="tab--container">
                    <div class="tab--content">
                        {$smarty.block.parent}
                    </div>
                </div>
                {if $sBlogArticles}
                    <div class="tab--container">
                        <div class="tab--content">
                            <div class="blog--content block-group">
                                {block name='frontend_bx_search_blog_headline'}
                                    <h1 class="search--headline">
                                        {s name='bx_search_blog_headline'}Zu "{$term}" wurden {$bxBlogCount} Blog-Beitr&auml;ge gefunden!{/s}
                                    </h1>
                                {/block}
                                {block name='frontend_bx_search_blog_content'}
                                    {include file='frontend/blog/listing.tpl' sPage=$sBlogPage}
                                {/block}
                            </div>
                        </div>
                    </div>
                {/if}
            </div>
        </div>
    {else}
        {$smarty.block.parent}
    {/if}
{/block}
