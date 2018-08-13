<?php
namespace ApacheSolrForTypo3\Solr\Domain\Search\Query;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017 <timo.hund@dkd.de>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\BigramPhraseFields;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Elevation;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Faceting;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\FieldCollapsing;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Filters;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Grouping;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Highlighting;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Operator;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\PhraseFields;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\QueryFields;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\ReturnFields;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Slops;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Sorting;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Sortings;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\Spellchecking;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\ParameterBuilder\TrigramPhraseFields;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Facets\SortingExpression;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteHashService;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\FieldProcessor\PageUidToHierarchy;
use ApacheSolrForTypo3\Solr\System\Configuration\TypoScriptConfiguration;
use ApacheSolrForTypo3\Solr\System\Logging\SolrLogManager;
use ApacheSolrForTypo3\Solr\Util;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * The QueryBuilder is responsible to build solr queries, that are used in the extension to query the solr server.
 *
 * @package ApacheSolrForTypo3\Solr\Domain\Search\Query
 */
class QueryBuilder {

    /**
     * Additional filters, which will be added to the query, as well as to
     * suggest queries.
     *
     * @var array
     */
    protected $additionalFilters = [];

    /**
     * @var TypoScriptConfiguration
     */
    protected $typoScriptConfiguration = null;

    /**
     * @var SolrLogManager;
     */
    protected $logger = null;

    /**
     * @var SiteHashService
     */
    protected $siteHashService = null;

    /**
     * @var Query
     */
    protected $queryToBuild = null;

    /**
     * QueryBuilder constructor.
     * @param TypoScriptConfiguration|null $configuration
     * @param SolrLogManager|null $solrLogManager
     * @param SiteHashService|null $siteHashService
     */
    public function __construct(TypoScriptConfiguration $configuration = null, SolrLogManager $solrLogManager = null, SiteHashService $siteHashService = null)
    {
        $this->typoScriptConfiguration = $configuration ?? Util::getSolrConfiguration();
        $this->logger = $solrLogManager ?? GeneralUtility::makeInstance(SolrLogManager::class, /** @scrutinizer ignore-type */ __CLASS__);
        $this->siteHashService = $siteHashService ?? GeneralUtility::makeInstance(SiteHashService::class);
    }

    /**
     * @param Query $query
     * @return QueryBuilder
     */
    public function startFrom(Query $query): QueryBuilder
    {
        $this->queryToBuild = $query;
        return $this;
    }

    /**
     * @param string $queryString
     * @return QueryBuilder
     */
    public function newSearchQuery($queryString): QueryBuilder
    {
        $this->queryToBuild = $this->getSearchQueryInstance($queryString);
        return $this;
    }

    /**
     * @param string $queryString
     * @return QueryBuilder
     */
    public function newSuggestQuery($queryString): QueryBuilder
    {
        $this->queryToBuild = $this->getSuggestQueryInstance($queryString);
        return $this;
    }

    /**
     * @return Query
     */
    public function getQuery(): Query
    {
        return $this->queryToBuild;
    }

    /**
     * Initializes the Query object and SearchComponents and returns
     * the initialized query object, when a search should be executed.
     *
     * @param string|null $rawQuery
     * @param int $resultsPerPage
     * @param array $additionalFiltersFromRequest
     * @return SearchQuery
     */
    public function buildSearchQuery($rawQuery, $resultsPerPage = 10, array $additionalFiltersFromRequest = []) : SearchQuery
    {
        if ($this->typoScriptConfiguration->getLoggingQuerySearchWords()) {
            $this->logger->log(SolrLogManager::INFO, 'Received search query', [$rawQuery]);
        }

        /* @var $query SearchQuery */
        return $this->newSearchQuery($rawQuery)
                ->useResultsPerPage($resultsPerPage)
                ->useReturnFieldsFromTypoScript()
                ->useQueryFieldsFromTypoScript()
                ->useInitialQueryFromTypoScript()
                ->useFiltersFromTypoScript()
                ->useFilterArray($additionalFiltersFromRequest)
                ->useFacetingFromTypoScript()
                ->useVariantsFromTypoScript()
                ->useGroupingFromTypoScript()
                ->useHighlightingFromTypoScript()
                ->usePhraseFieldsFromTypoScript()
                ->useBigramPhraseFieldsFromTypoScript()
                ->useTrigramPhraseFieldsFromTypoScript()
                ->getQuery();
    }

    /**
     * Builds a SuggestQuery with all applied filters.
     *
     * @param string $queryString
     * @param array $additionalFilters
     * @param integer $requestedPageId
     * @param string $groupList
     * @return SuggestQuery
     */
    public function buildSuggestQuery(string $queryString, array $additionalFilters, int $requestedPageId, string $groupList) : SuggestQuery
    {
        $this->newSuggestQuery($queryString)
            ->useFiltersFromTypoScript()
            ->useSiteHashFromTypoScript($requestedPageId)
            ->useUserAccessGroups(explode(',', $groupList))
            ->useOmitHeader();


        if (!empty($additionalFilters)) {
            $this->useFilterArray($additionalFilters);
        }

        return $this->queryToBuild;
    }

    /**
     * @param bool $omitHeader
     * @return QueryBuilder
     */
    public function useOmitHeader($omitHeader = true): QueryBuilder
    {
        $this->queryToBuild->setOmitHeader($omitHeader);

        return $this;
    }

    /**
     * Uses an array of filters and applies them to the query.
     *
     * @param array $filterArray
     * @return QueryBuilder
     */
    public function useFilterArray(array $filterArray): QueryBuilder
    {
        foreach ($filterArray as $key => $additionalFilter) {
            $this->useFilter($additionalFilter, $key);
        }

        return $this;
    }

    /**
     * Returns Query for Search which finds document for given page.
     * Note: The Connection is per language as recommended in ext-solr docs.
     *
     * @return Query
     */
    public function buildPageQuery($pageId)
    {
        $siteRepository = GeneralUtility::makeInstance(SiteRepository::class);
        $site = $siteRepository->getSiteByPageId($pageId);

        return $this->newSearchQuery('')
            ->useQueryString('*:*')
            ->useFilter('(type:pages AND uid:' . $pageId . ') OR (*:* AND pid:' . $pageId . ' NOT type:pages)', 'type')
            ->useFilter('siteHash:' . $site->getSiteHash(), 'siteHash')
            ->useReturnFields(ReturnFields::fromString('*'))
            ->useSortings(Sortings::fromString('type asc, title asc'))
            ->useQueryType('standard')
            ->getQuery();
    }

    /**
     * Returns a query for single record
     *
     * @return Query
     */
    public function buildRecordQuery($type, $uid, $pageId): Query
    {
        $siteRepository = GeneralUtility::makeInstance(SiteRepository::class);
        $site = $siteRepository->getSiteByPageId($pageId);

        return $this->newSearchQuery('')
            ->useQueryString('*:*')
            ->useFilter('type:' . $type . ' AND uid:' . $uid, 'type')
            ->useFilter('siteHash:' . $site->getSiteHash(), 'siteHash')
            ->useReturnFields(ReturnFields::fromString('*'))
            ->useSortings(Sortings::fromString('type asc, title asc'))
            ->useQueryType('standard')
            ->getQuery();
    }

    /**
     * Applies the queryString that is used to search
     *
     * @param string $queryString
     * @return QueryBuilder
     */
    public function useQueryString($queryString): QueryBuilder
    {
        $this->queryToBuild->setQuery($queryString);
        return $this;
    }

    /**
     * Applies the passed queryType to the query.
     *
     * @param string $queryType
     * @return QueryBuilder
     */
    public function useQueryType(string $queryType): QueryBuilder
    {
        $this->queryToBuild->addParam('qt', $queryType);
        return $this;
    }

    /**
     * Remove the queryType (qt) from the query.
     *
     * @return QueryBuilder
     */
    public function removeQueryType(): QueryBuilder
    {
        $this->queryToBuild->addParam('qt', null);
        return $this;
    }

    /**
     * Can be used to remove all sortings from the query.
     *
     * @return QueryBuilder
     */
    public function removeAllSortings(): QueryBuilder
    {
        $this->queryToBuild->clearSorts();
        return $this;
    }

    /**
     * Applies the passed sorting to the query.
     *
     * @param Sorting $sorting
     * @return QueryBuilder
     */
    public function useSorting(Sorting $sorting): QueryBuilder
    {
        if (strpos($sorting->getFieldName(), 'relevance') !== false) {
            $this->removeAllSortings();
            return $this;
        }

        $this->queryToBuild->addSort($sorting->getFieldName(), $sorting->getDirection());
        return $this;
    }

    /**
     * Applies the passed sorting to the query.
     *
     * @param Sortings $sortings
     * @return QueryBuilder
     */
    public function useSortings(Sortings $sortings): QueryBuilder
    {
        foreach($sortings->getSortings() as $sorting) {
            $this->useSorting($sorting);
        }

        return $this;
    }

    /**
     * @param int $resultsPerPage
     * @return QueryBuilder
     */
    public function useResultsPerPage($resultsPerPage): QueryBuilder
    {
        $this->queryToBuild->setRows($resultsPerPage);
        return $this;
    }

    /**
     * @param int $page
     * @return QueryBuilder
     */
    public function usePage($page): QueryBuilder
    {
        $this->queryToBuild->setStart($page);
        return $this;
    }

    /**
     * @param Operator $operator
     * @return QueryBuilder
     */
    public function useOperator(Operator $operator): QueryBuilder
    {
        $this->queryToBuild->setQueryDefaultOperator( $operator->getOperator());
        return $this;
    }

    /**
     * Remove the default query operator.
     *
     * @return QueryBuilder
     */
    public function removeOperator(): QueryBuilder
    {
        $this->queryToBuild->setQueryDefaultOperator(null);
        return $this;
    }

    /**
     * @return QueryBuilder
     */
    public function useSlopsFromTypoScript(): QueryBuilder
    {
        return $this->useSlops(Slops::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * @param Slops $slops
     * @return QueryBuilder
     */
    public function useSlops(Slops $slops): QueryBuilder
    {
        return $slops->build($this);
    }

    /**
     * Uses the configured boost queries from typoscript
     *
     * @return QueryBuilder
     */
    public function useBoostQueriesFromTypoScript(): QueryBuilder
    {
        $searchConfiguration = $this->typoScriptConfiguration->getSearchConfiguration();

        if (!empty($searchConfiguration['query.']['boostQuery'])) {
            return $this->useBoostQueries($searchConfiguration['query.']['boostQuery']);
        }

        if (!empty($searchConfiguration['query.']['boostQuery.'])) {
            $boostQueries = $searchConfiguration['query.']['boostQuery.'];
            return $this->useBoostQueries(array_values($boostQueries));
        }

        return $this;
    }

    /**
     * Uses the passed boostQuer(y|ies) for the query.
     *
     * @param string|array $boostQueries
     * @return QueryBuilder
     */
    public function useBoostQueries($boostQueries): QueryBuilder
    {
        $boostQueryArray = [];
        if(is_array($boostQueries)) {
            foreach($boostQueries as $boostQuery) {
                $boostQueryArray[] = ['key' => md5($boostQuery), 'query' => $boostQuery];
            }
        } else {
            $boostQueryArray[] = ['key' => md5($boostQueries), 'query' => $boostQueries];
        }

        $this->queryToBuild->getEDisMax()->setBoostQueries($boostQueryArray);
        return $this;
    }

    /**
     * Removes all boost queries from the query.
     *
     * @return QueryBuilder
     */
    public function removeAllBoostQueries(): QueryBuilder
    {
        $this->queryToBuild->getEDisMax()->clearBoostQueries();
        return $this;
    }

    /**
     * Uses the configured boostFunction from the typoscript configuration.
     *
     * @return QueryBuilder
     */
    public function useBoostFunctionFromTypoScript(): QueryBuilder
    {
        $searchConfiguration = $this->typoScriptConfiguration->getSearchConfiguration();
        if (!empty($searchConfiguration['query.']['boostFunction'])) {
            return $this->useBoostFunction($searchConfiguration['query.']['boostFunction']);
        }

        return $this;
    }

    /**
     * Uses the passed boostFunction for the query.
     *
     * @param string $boostFunction
     * @return QueryBuilder
     */
    public function useBoostFunction(string $boostFunction): QueryBuilder
    {
        $this->queryToBuild->getEDisMax()->setBoostFunctions($boostFunction);
        return $this;
    }

    /**
     * Removes all previously configured boost functions.
     *
     * @return $this
     */
    public function removeAllBoostFunctions()
    {
        $this->queryToBuild->getEDisMax()->setBoostFunctions(null);
        return $this;
    }

    /**
     * Uses the configured minimumMatch from the typoscript configuration.
     *
     * @return QueryBuilder
     */
    public function useMinimumMatchFromTypoScript(): QueryBuilder
    {
        $searchConfiguration = $this->typoScriptConfiguration->getSearchConfiguration();
        if (!empty($searchConfiguration['query.']['minimumMatch'])) {
            return $this->useMinimumMatch($searchConfiguration['query.']['minimumMatch']);
        }

        return $this;
    }

    /**
     * Uses the passed minimumMatch(mm) for the query.
     *
     * @param string $minimumMatch
     * @return QueryBuilder
     */
    public function useMinimumMatch(string $minimumMatch): QueryBuilder
    {
        $this->queryToBuild->getEDisMax()->setMinimumMatch($minimumMatch);
        return $this;
    }

    /**
     * Remove any previous passed minimumMatch parameter.
     *
     * @return QueryBuilder
     */
    public function removeMinimumMatch(): QueryBuilder
    {
        $this->queryToBuild->getEDisMax()->setMinimumMatch(null);
        return $this;
    }

    /**
     * @return QueryBuilder
     */
    public function useTieParameterFromTypoScript(): QueryBuilder
    {
        $searchConfiguration = $this->typoScriptConfiguration->getSearchConfiguration();
        if (empty($searchConfiguration['query.']['tieParameter'])) {
            return $this;
        }

        return $this->useTieParameter($searchConfiguration['query.']['tieParameter']);
    }

    /**
     * Applies the tie parameter to the query.
     *
     * @param mixed $tie
     * @return QueryBuilder
     */
    public function useTieParameter($tie): QueryBuilder
    {
        $this->queryToBuild->getEDisMax()->setTie($tie);
        return $this;
    }

    /**
     * Applies the configured query fields from the typoscript configuration.
     *
     * @return QueryBuilder
     */
    public function useQueryFieldsFromTypoScript(): QueryBuilder
    {
        return $this->useQueryFields(QueryFields::fromString($this->typoScriptConfiguration->getSearchQueryQueryFields()));
    }

    /**
     * Applies custom QueryFields to the query.
     *
     * @param QueryFields $queryFields
     * @return QueryBuilder
     */
    public function useQueryFields(QueryFields $queryFields): QueryBuilder
    {
        return $queryFields->build($this);
    }

    /**
     * Applies the configured return fields from the typoscript configuration.
     *
     * @return QueryBuilder
     */
    public function useReturnFieldsFromTypoScript(): QueryBuilder
    {
        $returnFieldsArray = (array)$this->typoScriptConfiguration->getSearchQueryReturnFieldsAsArray(['*', 'score']);
        return $this->useReturnFields(ReturnFields::fromArray($returnFieldsArray));
    }

    /**
     * Applies custom ReturnFields to the query.
     *
     * @param ReturnFields $returnFields
     * @return QueryBuilder
     */
    public function useReturnFields(ReturnFields $returnFields): QueryBuilder
    {
        return $returnFields->build($this);
    }

    /**
     * Can be used to apply the allowed sites from plugin.tx_solr.search.query.allowedSites to the query.
     *
     * @param int $requestedPageId
     * @return QueryBuilder
     */
    public function useSiteHashFromTypoScript(int $requestedPageId): QueryBuilder
    {
        $queryConfiguration = $this->typoScriptConfiguration->getObjectByPathOrDefault('plugin.tx_solr.search.query.', []);
        $allowedSites = $this->siteHashService->getAllowedSitesForPageIdAndAllowedSitesConfiguration($requestedPageId, $queryConfiguration['allowedSites']);
        return $this->useSiteHashFromAllowedSites($allowedSites);
    }

    /**
     * Can be used to apply a list of allowed sites to the query.
     *
     * @param string $allowedSites
     * @return QueryBuilder
     */
    public function useSiteHashFromAllowedSites($allowedSites): QueryBuilder
    {
        $isAnySiteAllowed = trim($allowedSites) === '*';
        if ($isAnySiteAllowed) {
            // no filter required
            return $this;
        }

        $allowedSites = GeneralUtility::trimExplode(',', $allowedSites);
        $filters = [];
        foreach ($allowedSites as $site) {
            $siteHash = $this->siteHashService->getSiteHashForDomain($site);
            $filters[] = 'siteHash:"' . $siteHash . '"';
        }

        $siteHashFilterString = implode(' OR ', $filters);
        return $this->useFilter($siteHashFilterString, 'siteHash');
    }

    /**
     * Can be used to use a specific filter string in the solr query.
     *
     * @param string $filterString
     * @param string $filterName
     * @return QueryBuilder
     */
    public function useFilter($filterString, $filterName = ''): QueryBuilder
    {
        $filterName = $filterName === '' ? $filterString : $filterName;
        $this->queryToBuild->addFilterQuery(['key' => $filterName, 'query' => $filterString]);
        return $this;
    }

    /**
     * Removes a filter by the fieldName.
     *
     * @param string $fieldName
     * @return QueryBuilder
     */
    public function removeFilterByFieldName($fieldName): QueryBuilder
    {
        return $this->removeFilterByFunction(
            function($key, $query) use ($fieldName) {
                $queryString = $query->getQuery();
                $storedFieldName = substr($queryString,0, strpos($queryString, ":"));
                return $storedFieldName == $fieldName;
            }
        );
    }

    /**
     * Removes a filter by the name of the filter (also known as key).
     *
     * @param string $name
     * @return QueryBuilder
     */
    public function removeFilterByName($name): QueryBuilder
    {
        return $this->removeFilterByFunction(
            function($key, $query) use ($name) {
                $key = $query->getKey();
                return $key == $name;
            }
        );
    }

    /**
     * Removes a filter by the filter value.
     *
     * @param string $value
     * @return QueryBuilder
     */
    public function removeFilterByValue($value): QueryBuilder
    {
        return $this->removeFilterByFunction(
            function($key, $query) use ($value) {
                $query = $query->getQuery();
                return $query == $value;
            }
        );
    }

    /**
     * @param \Closure $filterFunction
     * @return QueryBuilder
     */
    public function removeFilterByFunction($filterFunction) : QueryBuilder
    {
        $queries = $this->queryToBuild->getFilterQueries();
        foreach($queries as $key =>  $query) {
            $canBeRemoved = $filterFunction($key, $query);
            if($canBeRemoved) {
                unset($queries[$key]);
            }
        }

        $this->queryToBuild->setFilterQueries($queries);
        return $this;
    }

    /**
     * Can be used to filter the result on an applied list of user groups.
     *
     * @param array $groups
     * @return QueryBuilder
     */
    public function useUserAccessGroups(array $groups): QueryBuilder
    {
        $groups = array_map('intval', $groups);
        $groups[] = 0; // always grant access to public documents
        $groups = array_unique($groups);
        sort($groups, SORT_NUMERIC);

        $accessFilter = '{!typo3access}' . implode(',', $groups);
        $this->queryToBuild->removeFilterQuery('access');
        return $this->useFilter($accessFilter, 'access');
    }

    /**
     * Applies the configured initial query settings to set the alternative query for solr as required.
     *
     * @return QueryBuilder
     */
    public function useInitialQueryFromTypoScript(): QueryBuilder
    {
        if ($this->typoScriptConfiguration->getSearchInitializeWithEmptyQuery() || $this->typoScriptConfiguration->getSearchQueryAllowEmptyQuery()) {
            // empty main query, but using a "return everything"
            // alternative query in q.alt
            $this->useAlternativeQuery('*:*');
        }

        if ($this->typoScriptConfiguration->getSearchInitializeWithQuery()) {
            $this->useAlternativeQuery($this->typoScriptConfiguration->getSearchInitializeWithQuery());
        }

        return $this;
    }

    /**
     * Passes the alternative query to the Query
     * @param string $query
     * @return QueryBuilder
     */
    public function useAlternativeQuery(string $query): QueryBuilder
    {
        $this->queryToBuild->getEDisMax()->setQueryAlternative($query);
        return $this;
    }

    /**
     * Remove the alternative query from the Query.
     *
     * @return QueryBuilder
     */
    public function removeAlternativeQuery(): QueryBuilder
    {
        $this->queryToBuild->getEDisMax()->setQueryAlternative(null);
        return $this;
    }

    /**
     * Applies the configured facets from the typoscript configuration on the query.
     *
     * @return QueryBuilder
     */
    public function useFacetingFromTypoScript(): QueryBuilder
    {
        return $this->useFaceting(Faceting::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * Applies a custom Faceting configuration to the query.
     *
     * @param Faceting $faceting
     * @return QueryBuilder
     */
    public function useFaceting(Faceting $faceting): QueryBuilder
    {
        return $faceting->build($this);
    }

    /**
     * Applies the configured variants from the typoscript configuration on the query.
     *
     * @return QueryBuilder
     */
    public function useVariantsFromTypoScript(): QueryBuilder
    {
        return $this->useFieldCollapsing(FieldCollapsing::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * @param FieldCollapsing $fieldCollapsing
     * @return QueryBuilder
     */
    public function useFieldCollapsing(FieldCollapsing $fieldCollapsing): QueryBuilder
    {
        return $fieldCollapsing->build($this);
    }

    /**
     * Applies the configured groupings from the typoscript configuration to the query.
     *
     * @return QueryBuilder
     */
    public function useGroupingFromTypoScript(): QueryBuilder
    {
        return $this->useGrouping(Grouping::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * Applies a custom initialized grouping to the query.
     *
     * @param Grouping $grouping
     * @return QueryBuilder
     */
    public function useGrouping(Grouping $grouping): QueryBuilder
    {
        return $grouping->build($this);
    }

    /**
     * @param boolean $debugMode
     * @return QueryBuilder
     */
    public function useDebug($debugMode): QueryBuilder
    {
        if (!$debugMode) {
            $this->queryToBuild->addParam('debugQuery', null);
            $this->queryToBuild->addParam('echoParams', null);
            return $this;
        }

        $this->queryToBuild->addParam('debugQuery', 'true');
        $this->queryToBuild->addParam('echoParams', 'all');

        return $this;
    }

    /**
     * Applies the configured highlighting from the typoscript configuration to the query.
     *
     * @return QueryBuilder
     */
    public function useHighlightingFromTypoScript(): QueryBuilder
    {
        return $this->useHighlighting(Highlighting::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * @param Highlighting $highlighting
     * @return QueryBuilder
     */
    public function useHighlighting(Highlighting $highlighting): QueryBuilder
    {
        return $highlighting->build($this);
    }

    /**
     * Applies the configured filters (page section and other from typoscript).
     *
     * @return QueryBuilder
     */
    public function useFiltersFromTypoScript(): QueryBuilder
    {
        $filters = Filters::fromTypoScriptConfiguration($this->typoScriptConfiguration);
        $this->queryToBuild->setFilterQueries($filters->getValues());

        $this->useFilterArray($this->getAdditionalFilters());

        $searchQueryFilters = $this->typoScriptConfiguration->getSearchQueryFilterConfiguration();

        if (!is_array($searchQueryFilters) || count($searchQueryFilters) <= 0) {
            return $this;
        }

        // special filter to limit search to specific page tree branches
        if (array_key_exists('__pageSections', $searchQueryFilters)) {
            $pageIds = GeneralUtility::trimExplode(',', $searchQueryFilters['__pageSections']);
            $this->usePageSectionsFromPageIds($pageIds);
            $this->typoScriptConfiguration->removeSearchQueryFilterForPageSections();
        }

        return $this;
    }

    /**
     * Applies the configured elevation from the typoscript configuration.
     *
     * @return QueryBuilder
     */
    public function useElevationFromTypoScript(): QueryBuilder
    {
        return $this->useElevation(Elevation::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * @param Elevation $elevation
     * @return QueryBuilder
     */
    public function useElevation(Elevation $elevation): QueryBuilder
    {
        return $elevation->build($this);
    }

    /**
     * Applies the configured spellchecking from the typoscript configuration.
     *
     * @return QueryBuilder
     */
    public function useSpellcheckingFromTypoScript(): QueryBuilder
    {
        return $this->useSpellchecking(Spellchecking::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * @param Spellchecking $spellchecking
     * @return QueryBuilder
     */
    public function useSpellchecking(Spellchecking $spellchecking): QueryBuilder
    {
        return $spellchecking->build($this);
    }

    /**
     * Applies the passed pageIds as __pageSection filter.
     *
     * @param array $pageIds
     * @return QueryBuilder
     */
    public function usePageSectionsFromPageIds(array $pageIds = []): QueryBuilder
    {
        $filters = [];

        /** @var $processor PageUidToHierarchy */
        $processor = GeneralUtility::makeInstance(PageUidToHierarchy::class);
        $hierarchies = $processor->process($pageIds);

        foreach ($hierarchies as $hierarchy) {
            $lastLevel = array_pop($hierarchy);
            $filters[] = 'rootline:"' . $lastLevel . '"';
        }

        $pageSectionsFilterString = implode(' OR ', $filters);
        return $this->useFilter($pageSectionsFilterString, 'pageSections');
    }

    /**
     * Applies the configured phrase fields from the typoscript configuration to the query.
     *
     * @return QueryBuilder
     */
    public function usePhraseFieldsFromTypoScript(): QueryBuilder
    {
        return $this->usePhraseFields(PhraseFields::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * Applies a custom configured PhraseFields to the query.
     *
     * @param PhraseFields $phraseFields
     * @return QueryBuilder
     */
    public function usePhraseFields(PhraseFields $phraseFields): QueryBuilder
    {
        return $phraseFields->build($this);
    }

    /**
     * Applies the configured bigram phrase fields from the typoscript configuration to the query.
     *
     * @return QueryBuilder
     */
    public function useBigramPhraseFieldsFromTypoScript(): QueryBuilder
    {
        return $this->useBigramPhraseFields(BigramPhraseFields::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * Applies a custom configured BigramPhraseFields to the query.
     *
     * @param BigramPhraseFields $bigramPhraseFields
     * @return QueryBuilder
     */
    public function useBigramPhraseFields(BigramPhraseFields $bigramPhraseFields): QueryBuilder
    {
        return $bigramPhraseFields->build($this);
    }

    /**
     * Applies the configured trigram phrase fields from the typoscript configuration to the query.
     *
     * @return QueryBuilder
     */
    public function useTrigramPhraseFieldsFromTypoScript(): QueryBuilder
    {
        return $this->useTrigramPhraseFields(TrigramPhraseFields::fromTypoScriptConfiguration($this->typoScriptConfiguration));
    }

    /**
     * Applies a custom configured TrigramPhraseFields to the query.
     *
     * @param TrigramPhraseFields $trigramPhraseFields
     * @return QueryBuilder
     */
    public function useTrigramPhraseFields(TrigramPhraseFields $trigramPhraseFields): QueryBuilder
    {
        return $trigramPhraseFields->build($this);
    }

    /**
     * Retrieves the configuration filters from the TypoScript configuration, except the __pageSections filter.
     *
     * @return array
     */
    public function getAdditionalFilters() : array
    {
        // when we've build the additionalFilter once, we could return them
        if (count($this->additionalFilters) > 0) {
            return $this->additionalFilters;
        }

        $searchQueryFilters = $this->typoScriptConfiguration->getSearchQueryFilterConfiguration();
        if (!is_array($searchQueryFilters) || count($searchQueryFilters) <= 0) {
            return [];
        }

        $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);

        // all other regular filters
        foreach ($searchQueryFilters as $filterKey => $filter) {
            // the __pageSections filter should not be handled as additional filter
            if ($filterKey === '__pageSections') {
                continue;
            }

            $filterIsArray = is_array($searchQueryFilters[$filterKey]);
            if ($filterIsArray) {
                continue;
            }

            $hasSubConfiguration = is_array($searchQueryFilters[$filterKey . '.']);
            if ($hasSubConfiguration) {
                $filter = $cObj->stdWrap($searchQueryFilters[$filterKey], $searchQueryFilters[$filterKey . '.']);
            }

            $this->additionalFilters[$filterKey] = $filter;
        }

        return $this->additionalFilters;
    }

    /**
     * @param string $rawQuery
     * @return SearchQuery
     */
    protected function getSearchQueryInstance($rawQuery): SearchQuery
    {
        $query = GeneralUtility::makeInstance(SearchQuery::class);
        $query->setQuery($rawQuery);
        return $query;
    }


    /**
     * @param string $rawQuery
     * @return SuggestQuery
     */
    protected function getSuggestQueryInstance($rawQuery): SuggestQuery
    {
        $query = GeneralUtility::makeInstance(SuggestQuery::class, /** @scrutinizer ignore-type */ $rawQuery, /** @scrutinizer ignore-type */ $this->typoScriptConfiguration);

        return $query;
    }
}