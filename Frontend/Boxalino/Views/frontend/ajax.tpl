{block name='search_ajax_inner'}
    <ul class="results--list">
        {foreach $sSearchResults.sSuggestions as $suggestion}
            <li class="list--entry block-group result--item">
                <a class="search-result--link" href="{url controller='search' sSearch=$suggestion.text}" title="{$suggestion.text|escape}">
                    {$suggestion.html} ({$suggestion.hits})
                </a>
            </li>
        {/foreach}
    </ul>
    {$smarty.block.parent}
    {if $bxBlogSuggestionTotal > 0}
        <ul class="results--list suggestions--blog">
            <li class="entry-heading list--entry block-group result--item">
                <strong class="search-result--heading">{s name='bx_blog_results_heading'}Blog Beitr&auml;ge{/s}</strong>
            </li>
            {foreach $bxBlogSuggestions as $blog}
                <li class="list--entry block-group result--item">
                    <a class="search-result--link" href="{$blog.link}" title="{$blog.title}">
                        {$blog.title}
                    </a>
                </li>
            {/foreach}
            <li class="entry--all-results block-group result--item">
                <a href="{url controller="search"}?sSearch={$sSearchRequest.sSearch}&bxActiveTab=blog" class="search-result--link entry--all-results-link block">
                    <i class="icon--arrow-right"></i>
                    {s name='bx_show_all_blog_results'}Alle Blog-Ergebnisse anzeigen{/s}
                </a>
                <span class="entry--all-results-number block">
                    {$bxBlogSuggestionTotal} {s name='bx_blog_result_count'}Treffer{/s}
                </span>
            </li>
        </ul>
    {/if}
    {if $bxCategorySuggestionTotal > 0}
        <ul class="results--list suggestions--category">
            <li class="entry-heading list--entry block-group result--item">
                <strong class="search-result--heading">{s name='bx_category_results_heading'}Kategorien{/s}</strong>
            </li>
            {foreach $bxCategorySuggestions as $category}
                <li class="list--entry block-group result--item">
                    <a class="search-result--link" href="{$category.link}" title="{$category.value}">
                        {$blog.value} {if $category.total > -1}($category.total){/if}
                    </a>
                </li>
            {/foreach}
        </ul>
    {/if}
{/block}