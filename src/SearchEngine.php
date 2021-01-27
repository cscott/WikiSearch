<?php

/**
 * WSSearch MediaWiki extension
 * Copyright (C) 2021  Wikibase Solutions
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

namespace WSSearch;

use Elasticsearch\ClientBuilder;
use FatalError;
use Hooks;
use MediaWiki\MediaWikiServices;
use MWException;
use MWNamespace;

/**
 * Class Search
 *
 * @package WSSearch
 */
class SearchEngine {
	/**
	 * @var SearchEngineConfig
	 */
	private $config;

	/**
	 * @var SearchQueryBuilder
	 */
	private $query_builder;

	/**
	 * @var array
	 */
	private $translations = [];

	/**
	 * Search constructor.
	 *
	 * @param SearchEngineConfig $config
	 */
	public function __construct( SearchEngineConfig $config ) {
		$this->config = $config;
		$this->query_builder = SearchQueryBuilder::newCanonical();
	}

    /**
     * Executes the given ElasticSearch query and returns the result.
     *
     * @param array $query
     * @return array
     * @throws MWException
     * @throws FatalError
     */
    public static function doQuery( array $query ): array {
        $config = MediaWikiServices::getInstance()->getMainConfig();

        try {
            $hosts = $config->get( "WSSearchElasticSearchHosts" );
        } catch ( \ConfigException $e ) {
            $hosts = [ "localhost:9200" ];
        }

        // Allow other extensions to modify the query
        Hooks::run( "WSSearchBeforeElasticQuery", [ &$query, &$hosts ] );

        return ClientBuilder::create()->setHosts( $hosts )->build()->search( $query );
    }

	/**
	 * Sets the offset for the query. An offset of 10 means the first 10 results will not
	 * be returned. Useful for paged searches.
	 *
	 * @param int $offset
	 */
	public function setOffset( int $offset ) {
		$this->query_builder->setOffset( $offset );
	}

	/**
	 * Sets the currently active filters.
	 *
	 * @param array $active_filters
	 */
	public function setActiveFilters( array $active_filters ) {
		$this->query_builder->setActiveFilters( $active_filters );
	}

	/**
	 * Sets the available date ranges.
	 *
	 * @param array $ranges
	 */
	public function setAggregateDateRanges( array $ranges ) {
		$this->query_builder->setAggregateDateRanges( $ranges );
	}

	/**
	 * Sets the search term.
	 *
	 * @param string $search_term
	 */
	public function setSearchTerm( string $search_term ) {
		$this->query_builder->setSearchTerm( $search_term );
	}

	/**
	 * Limit the number of results returned.
	 *
	 * @param int $limit
	 */
	public function setLimit( int $limit ) {
		$this->query_builder->setLimit( $limit );
	}

	/**
	 * Performs an ElasticSearch query.
	 *
	 * @return array
	 *
	 * @throws MWException
	 */
	public function doSearch(): array {
		$elastic_query = $this->buildElasticQuery();

		$results = $this->doQuery( $elastic_query );
		$results = $this->applyResultTranslations( $results );

		return [
			"total" => $results["hits"]["total"],
			"hits"  => $results["hits"]["hits"],
			"aggs"  => $results["aggregations"]
		];
	}

	/**
	 * Builds the main ElasticSearch query.
	 *
	 * @return array
	 */
	private function buildElasticQuery(): array {
		$this->query_builder->setMainCondition(
		    $this->config->getConditionProperty(),
            $this->config->getConditionValue()
        );

		$this->query_builder->setAggregateFilters( $this->buildAggregateFilters() );

		return $this->query_builder->buildQuery();
	}

	/**
	 * Helper function to build the aggregate filters from the current config.
	 *
	 * @return array
	 */
	private function buildAggregateFilters(): array {
		$filters = [];

		foreach ( $this->config->getFacetProperties() as $facet ) {
			$translation_pair = explode( "=", $facet );
			$property_name = $translation_pair[0];

			if ( isset( $translation_pair[1] ) ) {
				$this->translations[$property_name] = $translation_pair[1];
			}

			$facet_property = new PropertyInfo( $property_name );
			$filters[$property_name] = [ "terms" => [ "field" => "P:" . $facet_property->getPropertyID() . "." . $facet_property->getPropertyType() . ".keyword" ] ];
		}

		return $filters;
	}

	/**
	 * Applies necessary translations to the ElasticSearch query result.
	 *
	 * @param array $results
	 * @return array
	 * @throws MWException
	 */
	private function applyResultTranslations( array $results ): array {
		$results = $this->doFacetTranslations( $results );
		$results = $this->doNamespaceTranslations( $results );
		$results = $this->doHitTranslations( $results );

		// Allow other extensions to modify the result
		Hooks::run( "WSSearchApplyResultTranslations", [ &$results ] );

		return $results;
	}

	/**
	 * Does facet translations.
	 *
	 * @param array $results
	 * @return array
	 */
	private function doFacetTranslations( array $results ): array {
		if ( !isset( $results["aggregations"] ) ) {
			return $results;
		}

		$aggregations = $results["aggregations"];

		foreach ( $aggregations as $property_name => $aggregate_data ) {
			if ( !isset( $this->translations[$property_name] ) ) {
				// No translation available
				continue;
			}

			$parts = explode( ":", $this->translations[$property_name] );

			if ( $parts[0] = "namespace" ) {
				foreach ( $results['aggregations'][$property_name]['buckets'] as $bucket_key => $bucket_value ) {
					$namespace = MWNamespace::getCanonicalName( $bucket_value['key'] );
					$results['aggregations'][$property_name]['buckets'][$bucket_key]['name'] = $namespace;
				}
			}
		}

		return $results;
	}

    /**
     * Translates namespace IDs to their canonical name.
     *
     * @param array $results
     * @return array
     */
    private function doNamespaceTranslations( array $results ): array {
        // Translate namespace IDs to their canonical name
        foreach ( $results['hits']['hits'] as $key => $value ) {
            $results['hits']['hits'][$key]['_source']['subject']['namespacename'] = MWNamespace::getCanonicalName( $value['_source']['subject']['namespace'] );
        }

        return $results;
    }

    /**
     * Removes any results the user is not allowed to view.
     *
     * @param array $results
     * @return array
     */
    private function doHitTranslations( array $results ): array {
        if ( !isset( $results["hits"]["hits"] ) ) {
            return $results;
        }

        $hits =& $results["hits"]["hits"];

        foreach ( $hits as $key => $hit ) {
            $revision_id = $hit["_source"]["subject"]["rev_id"];
            $revision = \Revision::newFromId( $revision_id );
            $title = $revision->getTitle();

            if ( !$title->userCan( "view" ) ) {
                unset( $hits[$key] );
            }

            if ( isset( $hit["_type"] ) ) {
                unset( $hits[$key]["_type"] );
            }
        }

        return $results;
    }
}
